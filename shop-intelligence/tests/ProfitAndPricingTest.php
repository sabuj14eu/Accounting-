<?php

declare(strict_types=1);

namespace Shop\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Cash\CashLedger;
use Shop\Cash\CashMovement;
use Shop\Costs\CostCategory;
use Shop\Costs\CostEntry;
use Shop\Costs\CostEntryType;
use Shop\Monitoring\ShopMonitor;
use Shop\Pricing\PriceReview;
use Shop\Profit\ProductProfitability;
use Shop\Profit\ProfitStatement;
use Shop\Profit\RevenueChannel;
use Shop\Truth\Certainty;
use Shop\Truth\EvidenceType;
use Shop\Truth\Figure;
use Shop\Truth\Money;

final class ProfitAndPricingTest extends TestCase
{
    private function rent(): CostEntry
    {
        return CostEntry::record(
            'REC/RENT/2026-08',
            CostCategory::PREMISES_AND_UTILITIES,
            'Czynsz',
            Money::parse('6 000,00'),
            CostEntryType::RECURRING,
            '2026-08',
            'Wynajmujący',
        );
    }

    /** R19 — §16: a recurring cost is EXPECTED until something confirms it. */
    public function test_r19_a_recurring_cost_is_expected_until_confirmed(): void
    {
        $rent = $this->rent();

        $this->assertTrue($rent->isExpected());
        $this->assertSame(Certainty::EXPECTED, $rent->certainty());

        $paid = $rent->confirmedBy(Money::parse('6 000,00'), CostEntryType::BANK, 'ING/2026-08-05/00012');

        $this->assertTrue($paid->isActual());
        $this->assertSame('ING/2026-08-05/00012', $paid->confirmedByReference);
    }

    /** R20 — "mark as paid" with nothing behind it is the button that launders EXPECTED into ACTUAL. */
    public function test_r20_a_recurring_cost_cannot_be_confirmed_by_a_person_typing_paid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bank line or an invoice/');

        $this->rent()->confirmedBy(Money::parse('6 000,00'), CostEntryType::USER_ENTERED, 'owner says so');
    }

    private function statement(): ProfitStatement
    {
        return new ProfitStatement(
            '2026-08',
            [
                new Figure(Money::parse('60 000,00'), EvidenceType::FISCAL_REPORT, 'Kasa fiskalna, sierpień'),
                new Figure(Money::parse('12 000,00'), EvidenceType::PLATFORM_STATEMENT, 'Glovo'),
            ],
            [
                CostEntry::record('FV/1', CostCategory::FOOD_AND_MATERIALS, 'Mięso', Money::parse('20 000,00'), CostEntryType::INVOICE, '2026-08'),
                $this->rent(),
                CostEntry::record('FV/2', CostCategory::OTHER_OPERATING, 'Wynagrodzenia', Money::parse('14 000,00'), CostEntryType::BANK, '2026-08'),
            ],
        );
    }

    /** R21 — the most dangerous number in the application carries its own warning. */
    public function test_r21_management_profit_is_never_presented_as_tax_profit(): void
    {
        $statement = $this->statement();

        $this->assertStringContainsString('not taxable income', $statement->headline());
        $this->assertStringContainsString('MANAGEMENT FIGURE', $statement->headline());
        $this->assertSame(ProfitStatement::NOT_TAX_PROFIT, $statement->jsonSerialize()['not_tax_profit']);
    }

    /** R22 — one unconfirmed rent makes the whole result EXPECTED, not ACTUAL. */
    public function test_r22_management_profit_inherits_the_worst_certainty_of_its_inputs(): void
    {
        $statement = $this->statement();

        // 72 000 − 40 000 = 32 000
        $this->assertSame(Money::parse('32 000,00')->grosze, $statement->managementProfitAmount()->grosze);
        $this->assertSame(Certainty::EXPECTED, $statement->managementProfit()->certainty);
        $this->assertTrue($statement->managementProfit()->isMixed());
        $this->assertSame(Money::parse('6 000,00')->grosze, $statement->expectedCostsIncluded()->grosze);

        $codes = array_map(static fn ($f) => $f->code, $statement->reviewFlags());
        $this->assertContains('PROFIT_INCLUDES_EXPECTED_COSTS', $codes);
    }

    public function test_costs_are_broken_down_by_the_three_categories(): void
    {
        $statement = $this->statement();

        $this->assertSame(
            Money::parse('20 000,00')->grosze,
            $statement->costsFor(CostCategory::FOOD_AND_MATERIALS)->amount->grosze,
        );
        $this->assertSame(
            Money::parse('6 000,00')->grosze,
            $statement->costsFor(CostCategory::PREMISES_AND_UTILITIES)->amount->grosze,
        );
        $this->assertSame(
            Money::parse('14 000,00')->grosze,
            $statement->costsFor(CostCategory::OTHER_OPERATING)->amount->grosze,
        );
    }

    private function kebab(RevenueChannel $channel, float $commission): ProductProfitability
    {
        return new ProductProfitability(
            'KEBAB_L',
            'Kebab duży',
            $channel,
            Money::parse('28,00'),
            Money::parse('9,00'),
            $commission,
            Money::parse('1,20'),
            Certainty::ESTIMATED,
            1_200,
        );
    }

    /** R23 — the price review explains; it never acts. */
    public function test_r23_the_price_review_never_changes_a_price(): void
    {
        $review = new PriceReview($this->kebab(RevenueChannel::DIRECT_CARD, 0.0));

        $this->assertStringContainsString('review, not a price change', $review->explanation());
        $this->assertFalse($review->jsonSerialize()['price_changed']);

        // There is no method on this class that produces a new price.
        $methods = get_class_methods($review);
        foreach (['setPrice', 'applyPrice', 'updatePrice', 'newPrice', 'apply'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
    }

    /** R24 — the same product can be green in the shop and red on a platform. */
    public function test_r24_a_platform_commission_can_turn_a_healthy_product_red(): void
    {
        $inShop = new PriceReview($this->kebab(RevenueChannel::DIRECT_CARD, 0.0), 60.0, 50.0);
        $onGlovo = new PriceReview($this->kebab(RevenueChannel::GLOVO, 30.0), 60.0, 50.0);

        $this->assertSame(PriceReview::GREEN, $inShop->signal());
        $this->assertSame(PriceReview::RED, $onGlovo->signal());

        $this->assertStringContainsString('🔴', $onGlovo->explanation());
        $this->assertStringContainsString('Glovo', $onGlovo->explanation());
        $this->assertStringContainsString('ESTIMATED', $onGlovo->explanation());
    }

    public function test_a_product_sold_below_its_ingredients_is_reported_as_loss_making(): void
    {
        $product = new ProductProfitability(
            'COLA',
            'Coca-Cola 0,5',
            RevenueChannel::GLOVO,
            Money::parse('6,00'),
            Money::parse('4,50'),
            30.0,
        );

        $this->assertTrue($product->isLossMaking());
        $this->assertSame(PriceReview::RED, (new PriceReview($product))->signal());
        $this->assertStringContainsString('loses money', (new PriceReview($product))->explanation());
    }

    /** R25 — §18: a cash difference is a question, never an accusation. */
    public function test_r25_a_cash_difference_requires_review_without_naming_a_cause(): void
    {
        $ledger = (new CashLedger('2026-08', Money::parse('500,00'), Money::parse('1 100,00'), 'Anna'))
            ->record(new CashMovement('2026-08-01', 'Utarg gotówkowy', Money::parse('900,00'), EvidenceType::FISCAL_REPORT))
            ->record(new CashMovement('2026-08-03', 'Wpłata do banku', Money::parse('-200,00'), EvidenceType::BANK_CONFIRMED));

        $this->assertSame(Money::parse('1 200,00')->grosze, $ledger->expectedClosing()->grosze);
        $this->assertSame(Money::parse('100,00')->grosze, $ledger->difference()->grosze);
        $this->assertSame('REQUIRES_REVIEW', $ledger->status());

        $flag = $ledger->reviewFlags()[0];
        $this->assertSame('CASH_DIFFERENCE_REQUIRES_REVIEW', $flag->code);
        $this->assertGreaterThanOrEqual(5, count($flag->possibleExplanations));
    }

    /** R26 — a cash count nobody signed cannot be questioned, corrected or defended. */
    public function test_r26_a_cash_count_must_name_who_counted_it(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must name who counted/');

        new CashLedger('2026-08', Money::parse('500,00'), Money::parse('1 100,00'));
    }

    public function test_the_cash_ledger_shows_a_running_balance_after_every_movement(): void
    {
        $ledger = (new CashLedger('2026-08', Money::parse('500,00')))
            ->record(new CashMovement('2026-08-03', 'Wpłata do banku', Money::parse('-200,00'), EvidenceType::BANK_CONFIRMED))
            ->record(new CashMovement('2026-08-01', 'Utarg gotówkowy', Money::parse('900,00'), EvidenceType::FISCAL_REPORT));

        $statement = $ledger->statement();

        // Sorted by date, with the balance after each line — a bank statement.
        $this->assertSame('2026-08-01', $statement[0]['movement']->date);
        $this->assertSame(Money::parse('1 400,00')->grosze, $statement[0]['balance']->grosze);
        $this->assertSame(Money::parse('1 200,00')->grosze, $statement[1]['balance']->grosze);
    }

    /** Three data points are not a trend, and the monitor knows it. */
    public function test_the_monitor_stays_silent_without_enough_history(): void
    {
        $statement = new ProfitStatement(
            '2026-08',
            [new Figure(Money::parse('10 000,00'), EvidenceType::FISCAL_REPORT, 'Kasa')],
            [CostEntry::record('FV/1', CostCategory::FOOD_AND_MATERIALS, 'Mięso', Money::parse('3 000,00'), CostEntryType::INVOICE, '2026-08')],
        );

        $quiet = (new ShopMonitor())->run($statement, [
            '2026-06' => Money::parse('60 000,00'),
            '2026-07' => Money::parse('62 000,00'),
        ]);

        $codes = array_map(static fn ($f) => $f->code, $quiet);
        $this->assertNotContains('REVENUE_BELOW_NORMAL', $codes);

        $loud = (new ShopMonitor())->run($statement, [
            '2026-05' => Money::parse('58 000,00'),
            '2026-06' => Money::parse('60 000,00'),
            '2026-07' => Money::parse('62 000,00'),
        ]);

        $codes = array_map(static fn ($f) => $f->code, $loud);
        $this->assertContains('REVENUE_BELOW_NORMAL', $codes);
    }
}
