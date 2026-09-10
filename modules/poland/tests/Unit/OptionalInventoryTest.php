<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poland\Inventory\StockPosition;
use Poland\Inventory\StockQuantity;
use Poland\Inventory\StockUnit;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Purchases\CostCategory;
use Poland\Purchases\LineResolution;
use Poland\Purchases\PostingPlan;
use Poland\Purchases\ProductMapping;
use Poland\Tests\Support\InvoiceFixtures;

/**
 * Section 5 of the design: inventory is optional per product, moves only on
 * approval of a tracked line, and never invents a closing stock.
 */
final class OptionalInventoryTest extends TestCase
{
    private function planWith(array $resolutions, ?\Poland\Ksef\Parsing\ParsedInvoice $invoice = null): PostingPlan
    {
        return PostingPlan::build(
            $invoice ?? InvoiceFixtures::drinks(),
            $resolutions,
            new DateTimeImmutable('2026-08-11'),
            3,
            CostCategory::GoodsForResaleAndMaterials,
        );
    }

    public function test_products_are_untracked_by_default(): void
    {
        $mapping = new ProductMapping(9, 'X', 'Anything', StockUnit::Piece, false, CostCategory::OtherExpenses);

        self::assertFalse($mapping->tracked);
        self::assertFalse((new LineResolution(InvoiceFixtures::drinks()->lines[0], $mapping))->isTracked());
    }

    public function test_an_untracked_line_posts_accounting_and_moves_no_stock(): void
    {
        $invoice = InvoiceFixtures::drinks();
        $plan = $this->planWith([
            new LineResolution($invoice->lines[0], InvoiceFixtures::cocaCola(tracked: false)),
            new LineResolution($invoice->lines[1], InvoiceFixtures::water(tracked: false)),
            new LineResolution($invoice->lines[2], InvoiceFixtures::onion()),
        ]);

        self::assertTrue($plan->isPostable());
        self::assertSame([], $plan->movements);
        self::assertSame('1464.00', $plan->deductibleNet->jsonSerialize(), 'accounting is unaffected by tracking');
        self::assertStringContainsString('Bez ruchu magazynowego', $plan->describe());
    }

    public function test_a_tracked_line_moves_stock_in_the_product_unit_via_pack_size(): void
    {
        $invoice = InvoiceFixtures::drinks();
        $plan = $this->planWith(InvoiceFixtures::drinksResolutions($invoice));

        self::assertCount(2, $plan->movements);
        self::assertSame('COLA-05', $plan->movements[0]->productCode);
        self::assertSame(240000, $plan->movements[0]->quantity->thousandths, '10 op × 24 = 240 szt');
        self::assertSame(StockUnit::Piece, $plan->movements[0]->quantity->unit);
        self::assertSame(1, $plan->movements[0]->sourceLineNo);
        self::assertSame(60000, $plan->movements[1]->quantity->thousandths, '5 op × 12 = 60 szt');
    }

    public function test_a_tracked_line_in_the_stock_unit_needs_no_pack_size(): void
    {
        $invoice = InvoiceFixtures::drinks();
        $plan = $this->planWith([
            LineResolution::unmapped($invoice->lines[0]),
            LineResolution::unmapped($invoice->lines[1]),
            new LineResolution($invoice->lines[2], InvoiceFixtures::onion(tracked: true)),
        ]);

        self::assertTrue($plan->isPostable(), implode(' | ', $plan->blockers));
        self::assertCount(1, $plan->movements);
        self::assertSame(20000, $plan->movements[0]->quantity->thousandths, '20 kg straight through');
        self::assertSame(StockUnit::Kilogram, $plan->movements[0]->quantity->unit);
    }

    public function test_a_line_with_unknown_pack_size_asks_once_and_blocks_until_answered(): void
    {
        $invoice = InvoiceFixtures::drinks();
        $plan = $this->planWith([
            new LineResolution($invoice->lines[0], InvoiceFixtures::cocaCola(true, null)),
            LineResolution::unmapped($invoice->lines[1]),
            LineResolution::unmapped($invoice->lines[2]),
        ]);

        self::assertFalse($plan->isPostable());
        self::assertStringContainsString('ile szt mieści jedno "op"', $plan->blockers[0]);
        self::assertSame([], $plan->movements, 'no movement is guessed');
    }

    public function test_a_tracked_line_without_a_quantity_blocks_rather_than_moving_one(): void
    {
        $xml = str_replace('<P_8B>10</P_8B>', '', InvoiceFixtures::xml('fa2-drinks-invoice.xml'));
        $invoice = (new FaInvoiceParser())->parse($xml, 'X-1');
        $plan = $this->planWith(InvoiceFixtures::drinksResolutions($invoice), $invoice);

        self::assertFalse($plan->isPostable());
        self::assertStringContainsString('nie podaje ilości', $plan->blockers[0]);
    }

    public function test_one_line_can_never_produce_two_movements(): void
    {
        $invoice = InvoiceFixtures::drinks();
        $plan = $this->planWith(InvoiceFixtures::drinksResolutions($invoice));

        $lineNos = array_map(static fn ($m): ?int => $m->sourceLineNo, $plan->movements);
        self::assertSame($lineNos, array_values(array_unique($lineNos)));
        // The database wall is unique (source_type, source_id, source_line_no);
        // the plan already never emits the same line twice.
    }

    public function test_no_opening_count_renders_as_no_opening_count_not_zero(): void
    {
        $position = new StockPosition('Coca-Cola 0,5 l', StockUnit::Piece, null, StockQuantity::of(480, StockUnit::Piece));

        self::assertSame(StockPosition::NO_OPENING_COUNT, $position->status());
        self::assertNull($position->bookQuantity());
        self::assertNull($position->impliedConsumption());
        self::assertStringContainsString('BRAK STANU POCZĄTKOWEGO', $position->describe());
        self::assertStringContainsString('nie zero', $position->describe());
    }

    public function test_without_a_count_the_book_quantity_is_shown_as_not_counted(): void
    {
        $position = new StockPosition(
            'Coca-Cola 0,5 l',
            StockUnit::Piece,
            StockQuantity::of(96, StockUnit::Piece),
            StockQuantity::of(480, StockUnit::Piece),
        );

        self::assertSame(StockPosition::NOT_COUNTED, $position->status());
        self::assertSame(576000, $position->bookQuantity()?->thousandths);
        self::assertNull($position->impliedConsumption());
    }

    public function test_implied_consumption_exists_only_when_a_count_exists(): void
    {
        $position = new StockPosition(
            'Coca-Cola 0,5 l',
            StockUnit::Piece,
            StockQuantity::of(96, StockUnit::Piece),
            StockQuantity::of(480, StockUnit::Piece),
            StockQuantity::of(130, StockUnit::Piece),
            new DateTimeImmutable('2026-09-01'),
        );

        self::assertSame(StockPosition::COUNTED, $position->status());
        self::assertSame(446000, $position->impliedConsumption()?->thousandths, '576 − 130');
        self::assertStringContainsString('zużycie/różnica 446 szt', $position->describe());
    }

    public function test_quantities_of_different_units_cannot_be_combined(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockQuantity::of(1, StockUnit::Kilogram)->plus(StockQuantity::of(1, StockUnit::Litre));
    }

    public function test_quantities_keep_thousandths_and_format_like_a_person_writes_them(): void
    {
        self::assertSame(12500, StockQuantity::of('12,5', StockUnit::Kilogram)->thousandths);
        self::assertSame('12,5 kg', StockQuantity::of('12,5', StockUnit::Kilogram)->format());
        self::assertSame('240 szt', StockQuantity::of(240, StockUnit::Piece)->format());
        self::assertSame('-5 op.', StockQuantity::of(-5, StockUnit::Pack)->format());
        self::assertSame(300, StockQuantity::of(0.1, StockUnit::Kilogram)->times(3)->thousandths);
    }

    public function test_supplier_unit_spellings_map_to_stock_units_or_to_nothing(): void
    {
        self::assertSame(StockUnit::Piece, StockUnit::fromInvoiceUnit('szt.'));
        self::assertSame(StockUnit::Pack, StockUnit::fromInvoiceUnit('karton'));
        self::assertSame(StockUnit::Pack, StockUnit::fromInvoiceUnit('OP.'));
        self::assertSame(StockUnit::Kilogram, StockUnit::fromInvoiceUnit('kg'));
        self::assertNull(StockUnit::fromInvoiceUnit('worek'), 'an unknown unit is a question, not a default');
        self::assertNull(StockUnit::fromInvoiceUnit(null));
    }
}
