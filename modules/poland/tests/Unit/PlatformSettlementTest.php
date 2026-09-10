<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\SalesChannel;
use Poland\Platforms\Platform;
use Poland\Platforms\PlatformSettlement;
use Poland\Platforms\SettlementStatus;
use Poland\Platforms\VatTreatment;

/**
 * Section 7 of the design: Glovo as an accounting settlement source that
 * refuses to guess its VAT treatment and never rounds a payout gap to "fine".
 */
final class PlatformSettlementTest extends TestCase
{
    /**
     * @param Money|null|false $received false = the default receipt of 7 572,00; null = no receipt
     * @param array|null|false $byRate false = the default single-rate split; null = no split given
     */
    private function august(
        VatTreatment $treatment = VatTreatment::DomesticInvoice,
        Money|null|false $received = false,
        array|null|false $byRate = false,
        array $deductions = [],
        ?Money $tolerance = null,
    ): PlatformSettlement {
        if ($byRate === false) {
            $byRate = ['0.08' => Money::parse('12000.00')];
        }
        if ($received === false) {
            $received = Money::parse('7572.00');
        }

        return new PlatformSettlement(
            Platform::Glovo,
            Period::parse('2026-08'),
            Money::parse('12000.00'),
            $byRate,
            Money::parse('3600.00'),
            Money::parse('828.00'),
            $deductions,
            $treatment,
            $received,
            $tolerance,
        );
    }

    public function test_expected_payout_is_gross_minus_commission_gross_minus_named_deductions(): void
    {
        $settlement = $this->august(deductions: ['marketing' => Money::parse('100.00')]);

        self::assertSame('4428.00', $settlement->commissionGross()->jsonSerialize());
        self::assertSame('7472.00', $settlement->expectedPayout()->jsonSerialize());
        self::assertSame('-100.00', $settlement->difference()?->jsonSerialize(), 'the bank received 100 MORE than expected');
        self::assertEqualsWithDelta(0.3773, $settlement->effectiveCommissionRate(), 0.0001);
    }

    public function test_no_payout_recorded_is_never_reconciled(): void
    {
        $settlement = $this->august(received: null, tolerance: Money::parse('1000000.00'));

        self::assertSame(SettlementStatus::NoPayoutRecorded, $settlement->status());
        self::assertNull($settlement->difference());
        self::assertNotEmpty($settlement->reviewExplanations());
    }

    public function test_a_matching_receipt_is_reconciled_and_a_gap_is_a_question(): void
    {
        self::assertSame(SettlementStatus::Reconciled, $this->august()->status());
        self::assertSame([], $this->august()->reviewExplanations());

        $short = $this->august(received: Money::parse('7532.00'));
        self::assertSame(SettlementStatus::RequiresReview, $short->status());
        self::assertSame('40.00', $short->difference()?->jsonSerialize());
        foreach ($short->reviewExplanations() as $explanation) {
            self::assertDoesNotMatchRegularExpression('/kradzie|oszust|ukrad/i', $explanation);
        }

        $withinTolerance = $this->august(received: Money::parse('7571.50'), tolerance: Money::parse('1.00'));
        self::assertSame(SettlementStatus::Reconciled, $withinTolerance->status());
    }

    public function test_unknown_vat_treatment_records_but_refuses_to_post_vat(): void
    {
        $settlement = $this->august(VatTreatment::Unknown);

        // Recorded and reconciled — those do not depend on the decision.
        self::assertSame(SettlementStatus::Reconciled, $settlement->status());
        self::assertContains('vat_treatment', $settlement->missingFields());
        self::assertCount(1, $settlement->salesLines(), 'sales are still the shop\'s sales');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NIEUSTALONE traktowanie VAT');
        $settlement->vatRows();
    }

    public function test_import_of_services_produces_output_and_input_rows(): void
    {
        $rows = $this->august(VatTreatment::ImportOfServices)->vatRows();

        self::assertSame('828.00', $rows['output_vat']->jsonSerialize());
        self::assertSame('828.00', $rows['input_vat']->jsonSerialize());
        self::assertStringContainsString('Import usług', $rows['note']);
    }

    public function test_a_domestic_commission_invoice_contributes_nothing_here_to_avoid_double_counting(): void
    {
        $rows = $this->august(VatTreatment::DomesticInvoice)->vatRows();

        self::assertTrue($rows['output_vat']->isZero());
        self::assertTrue($rows['input_vat']->isZero());
        self::assertStringContainsString('KSeF', $rows['note']);
    }

    public function test_the_month_s_platform_sales_become_glovo_lines_by_rate(): void
    {
        $lines = $this->august(byRate: ['0.08' => Money::parse('11000.00'), '0.23' => Money::parse('1000.00')])->salesLines();

        self::assertCount(2, $lines);
        self::assertSame(SalesChannel::GLOVO, $lines[0]->channel);
        self::assertSame('0.08', $lines[0]->designation);
        self::assertSame('11000.00', $lines[0]->gross->jsonSerialize());
        self::assertStringContainsString('Glovo', (string) $lines[0]->note);
    }

    public function test_a_missing_rate_breakdown_is_missing_field_not_a_default_rate(): void
    {
        $settlement = $this->august(byRate: null);

        self::assertFalse($settlement->canProduceSalesLines());
        self::assertContains('gross_orders_by_rate', $settlement->missingFields());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MISSING_FIELD');
        $settlement->salesLines();
    }

    public function test_a_rate_split_that_does_not_add_up_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->august(byRate: ['0.08' => Money::parse('11000.00')]);
    }

    public function test_negative_magnitudes_and_unnamed_deductions_are_refused(): void
    {
        try {
            new PlatformSettlement(Platform::Glovo, Period::parse('2026-08'), Money::parse('-1'), null, Money::zero(), Money::zero(), [], VatTreatment::Unknown);
            self::fail('negative gross accepted');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->august(deductions: ['' => Money::parse('5.00')]);
    }

    public function test_the_treatment_is_a_decision_with_a_named_reason(): void
    {
        self::assertFalse(VatTreatment::Unknown->isDecided());
        self::assertTrue(VatTreatment::DomesticInvoice->isDecided());
        self::assertSame(SalesChannel::GLOVO, Platform::Glovo->salesChannel());
        self::assertSame(SalesChannel::OTHER, Platform::UberEats->salesChannel());
    }
}
