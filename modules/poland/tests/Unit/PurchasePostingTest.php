<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\PurchaseRegister;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Purchases\CostCategory;
use Poland\Purchases\InvoiceFingerprint;
use Poland\Purchases\PostingPlan;
use Poland\Purchases\RegisterResolution;
use Poland\Tests\Support\InvoiceFixtures;

/**
 * Section 4.3, 8.3 and 12 of the design: what a posting is, when its VAT may
 * be deducted, how it is reversed, and the walls against booking twice.
 */
final class PurchasePostingTest extends TestCase
{
    private function plan(
        ?\Poland\Ksef\Parsing\ParsedInvoice $invoice = null,
        string $receivedAt = '2026-08-11 09:52:00',
        int $allowed = 3,
        float $share = 1.0,
        ?Period $vatPeriod = null,
    ): PostingPlan {
        $invoice ??= InvoiceFixtures::drinks();

        return PostingPlan::build(
            $invoice,
            InvoiceFixtures::drinksResolutions($invoice),
            new DateTimeImmutable($receivedAt),
            $allowed,
            CostCategory::GoodsForResaleAndMaterials,
            $share,
            $vatPeriod,
        );
    }

    public function test_amounts_are_posted_from_the_header_split_by_rate(): void
    {
        $plan = $this->plan();

        self::assertTrue($plan->isPostable(), implode(' | ', $plan->blockers));
        self::assertSame('2026-08', $plan->bookingPeriod?->toString());
        self::assertSame('1400.00', $plan->byRate['23%']['net']->jsonSerialize());
        self::assertSame('322.00', $plan->byRate['23%']['vat']->jsonSerialize());
        self::assertSame('64.00', $plan->byRate['5%']['net']->jsonSerialize());
        self::assertSame('3.20', $plan->byRate['5%']['vat']->jsonSerialize());
        self::assertSame('1464.00', $plan->deductibleNet->jsonSerialize());
        self::assertSame('325.20', $plan->deductibleInputVat->jsonSerialize());
        self::assertTrue($plan->nonDeductibleNet->isZero());
        self::assertSame(10, $plan->costCategory->kpirColumn());
    }

    public function test_the_description_says_exactly_what_approving_will_do(): void
    {
        $text = $this->plan()->describe();

        self::assertStringContainsString(Money::parse('1400')->format().' netto + '.Money::parse('322')->format().' VAT (23%)', $text);
        self::assertStringContainsString(Money::parse('64')->format().' netto + '.Money::parse('3.20')->format().' VAT (5%)', $text);
        self::assertStringContainsString('sierpień 2026', $text);
        self::assertStringContainsString('Coca-Cola 0,5 l +240 szt', $text);
        self::assertStringContainsString('Woda 0,5 l +60 szt', $text);
        self::assertStringNotContainsString('Cebula', $text, 'an untracked product never appears as a stock movement');
    }

    public function test_vat_period_defaults_to_the_later_of_invoice_month_and_receipt_month(): void
    {
        // Received in the invoice month.
        self::assertSame('2026-08', $this->plan()->vatPeriod?->toString());

        // Invoice dated August, received (KSeF number assigned) in September.
        self::assertSame('2026-09', $this->plan(receivedAt: '2026-09-02 08:00:00')->vatPeriod?->toString());
    }

    public function test_vat_period_is_never_earlier_than_receipt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wcześniejszy niż miesiąc otrzymania');

        $this->plan(receivedAt: '2026-09-02 08:00:00', vatPeriod: Period::parse('2026-08'));
    }

    public function test_vat_period_may_be_deferred_only_within_the_allowed_window(): void
    {
        $plan = $this->plan(allowed: 3);

        self::assertSame('2026-11', $plan->deductionWindowEnd()->toString());
        self::assertSame('2026-11', $plan->withVatPeriod(Period::parse('2026-11'))->vatPeriod?->toString());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wykracza poza dozwolone okno');
        $plan->withVatPeriod(Period::parse('2026-12'));
    }

    public function test_an_invoice_past_its_deduction_window_is_flagged(): void
    {
        $plan = $this->plan(allowed: 3);

        self::assertFalse($plan->deductionWindowClosed(Period::parse('2026-11')));
        self::assertTrue($plan->deductionWindowClosed(Period::parse('2026-12')));
    }

    public function test_a_partial_deductible_share_splits_the_amounts_and_says_so(): void
    {
        $plan = $this->plan(share: 0.5);

        self::assertSame('732.00', $plan->deductibleNet->jsonSerialize());
        self::assertSame('732.00', $plan->nonDeductibleNet->jsonSerialize());
        self::assertSame('162.60', $plan->deductibleInputVat->jsonSerialize());
        self::assertSame('162.60', $plan->nonDeductibleVat->jsonSerialize());
        self::assertTrue((bool) array_filter($plan->notes, static fn (string $n): bool => str_contains($n, 'Odliczenie 50%')));
    }

    public function test_a_correction_is_the_negation_of_what_it_reverses(): void
    {
        $reversal = $this->plan()->negated();

        self::assertSame('-1464.00', $reversal->deductibleNet->jsonSerialize());
        self::assertSame('-325.20', $reversal->deductibleInputVat->jsonSerialize());
        self::assertSame('-1400.00', $reversal->byRate['23%']['net']->jsonSerialize());
        self::assertCount(2, $reversal->movements);
        self::assertSame(-240000, $reversal->movements[0]->quantity->thousandths);
        self::assertSame(\Poland\Inventory\MovementType::Reversal, $reversal->movements[0]->type);
        self::assertStringContainsString('ODWRÓCENIE', $reversal->notes[0]);
    }

    public function test_a_line_with_an_unknown_vat_rate_blocks_posting(): void
    {
        $xml = str_replace('<P_12>5</P_12>', '<P_12>oo</P_12>', InvoiceFixtures::xml('fa2-drinks-invoice.xml'));
        $invoice = (new FaInvoiceParser())->parse($xml, 'X-1');

        $plan = $this->plan($invoice);

        self::assertFalse($plan->isPostable());
        self::assertStringContainsString('stawka VAT "oo" nie jest znana', $plan->blockers[0]);
        self::assertStringStartsWith('Nie można zatwierdzić', $plan->describe());
    }

    public function test_a_foreign_currency_invoice_is_refused_without_an_exchange_rate(): void
    {
        $xml = str_replace('<KodWaluty>PLN</KodWaluty>', '<KodWaluty>EUR</KodWaluty>', InvoiceFixtures::xml('fa2-drinks-invoice.xml'));
        $plan = $this->plan((new FaInvoiceParser())->parse($xml, 'X-2'));

        self::assertFalse($plan->isPostable());
        self::assertStringContainsString('walucie EUR', $plan->blockers[0]);
    }

    public function test_a_header_without_a_rate_split_is_posted_as_one_bucket_and_noted(): void
    {
        $invoice = InvoiceFixtures::parse('fa-no-namespace-partial.xml', 'P-1');
        // The partial fixture has no invoice date and no net/VAT; it cannot post.
        $plan = PostingPlan::build($invoice, [], new DateTimeImmutable('2026-08-11'), 3, CostCategory::OtherExpenses);

        self::assertFalse($plan->isPostable());
        self::assertStringContainsString('Brak daty wystawienia', $plan->blockers[0]);
        self::assertStringContainsString('MISSING_FIELD', $plan->blockers[1]);
    }

    // --- the purchase register: one source per month, never a sum --------------

    public function test_the_purchase_register_is_the_sum_of_posted_documents_only(): void
    {
        $period = Period::parse('2026-08');
        $fromPostings = new PurchaseRegister($period, Money::parse('1464.00'), Money::parse('325.20'), 1);

        $resolution = RegisterResolution::resolve($period, $fromPostings, null);

        self::assertSame(RegisterResolution::POSTINGS, $resolution->source);
        self::assertSame($fromPostings, $resolution->register);
    }

    public function test_a_month_with_manual_summary_and_postings_is_review_not_a_sum(): void
    {
        $period = Period::parse('2026-08');
        $fromPostings = new PurchaseRegister($period, Money::parse('1464.00'), Money::parse('325.20'), 1);
        $manual = new PurchaseRegister($period, Money::parse('5000.00'), Money::parse('1150.00'), 12);

        $resolution = RegisterResolution::resolve($period, $fromPostings, $manual);

        self::assertTrue($resolution->isConflict());
        self::assertNull($resolution->register, 'a conflicted month has NO register — the engine reports an upper bound');
        self::assertStringContainsString('Nie są sumowane', $resolution->reason);
    }

    public function test_a_month_with_only_the_manual_summary_keeps_using_it(): void
    {
        $period = Period::parse('2026-07');
        $manual = new PurchaseRegister($period, Money::parse('5000.00'), Money::parse('1150.00'), 12);

        self::assertSame(RegisterResolution::MANUAL, RegisterResolution::resolve($period, null, $manual)->source);
        self::assertSame(RegisterResolution::NONE, RegisterResolution::resolve($period, null, null)->source);
        self::assertNull(RegisterResolution::resolve($period, null, null)->register);
    }

    // --- the second duplicate wall ---------------------------------------------

    public function test_the_same_invoice_under_two_ksef_numbers_has_one_fingerprint(): void
    {
        $date = new DateTimeImmutable('2026-08-11');
        $gross = Money::parse('1789.20');

        $a = InvoiceFingerprint::of('113-231-69-39', 'FV/2026/08/417', $date, $gross);
        $b = InvoiceFingerprint::of('1132316939', 'fv 2026-08-417', $date, $gross);
        $c = InvoiceFingerprint::of('1132316939', 'FV/2026/08/418', $date, $gross);

        self::assertNotNull($a);
        self::assertSame($a, $b, 'separators and case never make two numbers');
        self::assertNotSame($a, $c);
    }

    public function test_an_incomplete_invoice_has_no_fingerprint_rather_than_a_shared_one(): void
    {
        self::assertNull(InvoiceFingerprint::of(null, 'FV/1', new DateTimeImmutable('2026-08-11'), Money::parse('1')));
        self::assertNull(InvoiceFingerprint::of('1132316939', null, new DateTimeImmutable('2026-08-11'), Money::parse('1')));
        self::assertNull(InvoiceFingerprint::of('1132316939', 'FV/1', null, Money::parse('1')));
        self::assertNull(InvoiceFingerprint::of('1132316939', 'FV/1', new DateTimeImmutable('2026-08-11'), null));
    }
}
