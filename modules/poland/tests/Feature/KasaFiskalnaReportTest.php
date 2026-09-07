<?php

declare(strict_types=1);

namespace Poland\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poland\Domain\Enums\ContributionDeductionBasis;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\FiscalSalesReport;
use Poland\Domain\Ledger;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\PurchaseRegister;
use Poland\Domain\TaxProfile;
use Poland\Rates\MissingRateException;
use Poland\Reporting\SettlementEngine;

/**
 * The workflow this module exists for: the taxpayer enters ONE number a month —
 * the fiscal cash register's monthly total — and gets back what they owe.
 */
final class KasaFiskalnaReportTest extends TestCase
{
    private function engine(): SettlementEngine
    {
        return SettlementEngine::withDefaultRates();
    }

    private function lumpSumProfile(
        float $rate = 0.03,
        VatStatus $vat = VatStatus::ExemptBySize,
        ZusScheme $zus = ZusScheme::Full,
    ): TaxProfile {
        return new TaxProfile(
            name: 'Sklep testowy',
            pitRegime: PitRegime::LumpSum,
            vatStatus: $vat,
            zusScheme: $zus,
            businessStartedAt: Period::of(2021, 3),
            lumpSumRate: $rate,
        );
    }

    /** @param array<int,string> $monthlyGross */
    private function ledgerOf(array $monthlyGross, VatStatus $vat, int $year = 2026): Ledger
    {
        $ledger = new Ledger();
        foreach ($monthlyGross as $month => $gross) {
            $period = Period::of($year, $month);
            $ledger->recordSales(
                $vat->settlesVat()
                    ? FiscalSalesReport::singleRate($period, $gross, '0.23')
                    : FiscalSalesReport::exempt($period, $gross),
            );
        }

        return $ledger;
    }

    public function test_one_sales_figure_produces_zus_vat_and_pit(): void
    {
        $profile = $this->lumpSumProfile();
        $ledger = $this->ledgerOf(array_fill(1, 8, '18000.00'), VatStatus::ExemptBySize);

        $report = $this->engine()->settle($profile, $ledger, Period::of(2026, 8));

        self::assertSame(1800000, $report->grossSales->grosze);
        // No VAT: the taxpayer is under the subject exemption.
        self::assertTrue($report->vat->amountToPay->isZero());
        // Full ZUS 2026 plus the middle health band.
        self::assertSame(275734, $report->zus->total->grosze);
        self::assertTrue($report->pit->advanceDue->isPositive());
        self::assertSame(
            $report->zus->total->plus($report->vat->amountToPay)->plus($report->pit->advanceDue)->grosze,
            $report->totalDue->grosze,
        );
    }

    public function test_a_vat_registered_taxpayer_pays_pit_on_the_net_amount(): void
    {
        // The single most damaging way to get this wrong: taxing the gross
        // takings under ryczałt overstates the base by 23%.
        $registered = $this->engine()->settle(
            $this->lumpSumProfile(vat: VatStatus::Registered),
            $this->ledgerOf([1 => '12300.00'], VatStatus::Registered),
            Period::of(2026, 1),
        );

        self::assertSame(1000000, $registered->revenueForIncomeTax->grosze);
        self::assertSame(230000, $registered->vat->amountToPay->grosze);
    }

    public function test_an_exempt_taxpayer_has_no_vat_inside_the_takings(): void
    {
        $exempt = $this->engine()->settle(
            $this->lumpSumProfile(),
            $this->ledgerOf([1 => '12300.00'], VatStatus::ExemptBySize),
            Period::of(2026, 1),
        );

        self::assertSame(1230000, $exempt->revenueForIncomeTax->grosze);
    }

    public function test_pit_advances_accumulate_correctly_across_the_year(): void
    {
        // The sum of every month's advance must equal the year's cumulative tax.
        $profile = $this->lumpSumProfile(0.085);
        $ledger = $this->ledgerOf(array_fill(1, 12, '20000.00'), VatStatus::ExemptBySize);

        $run = $this->engine()->replayYear($profile, $ledger, Period::of(2026, 12));

        $sumOfAdvances = Money::sum(array_map(
            static fn (array $m): Money => $m['pit']->advanceDue,
            array_values($run),
        ));

        self::assertSame(
            $run['2026-12']['pit']->taxYearToDate->grosze,
            $sumOfAdvances->grosze,
            'Monthly advances must add up to the cumulative annual tax.',
        );
    }

    public function test_the_health_band_step_raises_zus_for_the_rest_of_the_year(): void
    {
        $profile = $this->lumpSumProfile(0.085);
        // 25 000 a month crosses 60 000 during March.
        $ledger = $this->ledgerOf(array_fill(1, 6, '25000.00'), VatStatus::ExemptBySize);

        $run = $this->engine()->replayYear($profile, $ledger, Period::of(2026, 6));

        self::assertSame(46166, $run['2026-01']['zus']->health->grosze, 'January is still on the old contribution year');
        self::assertSame(49835, $run['2026-02']['zus']->health->grosze);
        self::assertSame(83058, $run['2026-04']['zus']->health->grosze, 'Higher band after 60 000 is passed');
        self::assertSame(83058, $run['2026-06']['zus']->health->grosze, 'And it stays there');
    }

    public function test_an_unrecorded_month_is_refused_rather_than_treated_as_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a month of zero sales/');

        $this->engine()->settle(
            $this->lumpSumProfile(),
            $this->ledgerOf([1 => '10000.00'], VatStatus::ExemptBySize),
            Period::of(2026, 5),
        );
    }

    public function test_a_gap_earlier_in_the_year_is_reported_as_incomplete(): void
    {
        $ledger = $this->ledgerOf([1 => '10000.00', 2 => '10000.00', 4 => '10000.00'], VatStatus::ExemptBySize);

        $report = $this->engine()->settle($this->lumpSumProfile(), $ledger, Period::of(2026, 4));

        self::assertNotEmpty(array_filter(
            $report->warnings,
            static fn (string $w): bool => str_contains($w, 'BRAK DANYCH ZA MIESIĄCE') && str_contains($w, '2026-03'),
        ));
    }

    public function test_a_month_with_no_rate_version_is_refused(): void
    {
        $ledger = new Ledger();
        $ledger->recordSales(FiscalSalesReport::exempt(Period::of(2028, 3), '10000.00'));

        $this->expectException(MissingRateException::class);
        $this->engine()->settle($this->lumpSumProfile(), $ledger, Period::of(2028, 3));
    }

    public function test_income_regimes_without_costs_are_marked_as_estimates(): void
    {
        $profile = new TaxProfile(
            name: 'Usługi',
            pitRegime: PitRegime::Scale,
            vatStatus: VatStatus::ExemptBySize,
            zusScheme: ZusScheme::Full,
            businessStartedAt: Period::of(2021, 3),
        );

        $report = $this->engine()->settle(
            $profile,
            $this->ledgerOf(array_fill(1, 6, '20000.00'), VatStatus::ExemptBySize),
            Period::of(2026, 6),
        );

        self::assertTrue($report->isEstimate);
        self::assertNotEmpty(array_filter(
            $report->warnings,
            static fn (string $w): bool => str_contains($w, 'górną granicą'),
        ));
    }

    public function test_recorded_costs_lower_both_pit_and_vat(): void
    {
        $profile = $this->lumpSumProfile(vat: VatStatus::Registered);
        $ledger = $this->ledgerOf([1 => '123000.00'], VatStatus::Registered);

        $before = $this->engine()->settle($profile, $ledger, Period::of(2026, 1));

        $ledger->recordPurchases(new PurchaseRegister(
            Period::of(2026, 1),
            Money::parse('20000.00'),
            Money::parse('4600.00'),
            8,
        ));
        $after = $this->engine()->settle($profile, $ledger, Period::of(2026, 1));

        self::assertSame(460000, $before->vat->amountToPay->minus($after->vat->amountToPay)->grosze);
        // Ryczałt taxes revenue, so costs do not move PIT — which is the point.
        self::assertSame($before->pit->advanceDue->grosze, $after->pit->advanceDue->grosze);
    }

    public function test_the_cash_deduction_basis_shifts_contributions_by_one_month(): void
    {
        $accrued = $this->lumpSumProfile(0.085);
        $cash = new TaxProfile(
            name: 'Sklep testowy',
            pitRegime: PitRegime::LumpSum,
            vatStatus: VatStatus::ExemptBySize,
            zusScheme: ZusScheme::Full,
            businessStartedAt: Period::of(2021, 3),
            lumpSumRate: 0.085,
            deductionBasis: ContributionDeductionBasis::PaidInMonth,
        );

        $ledger = $this->ledgerOf(array_fill(1, 3, '20000.00'), VatStatus::ExemptBySize);

        $a = $this->engine()->settle($accrued, $ledger, Period::of(2026, 3));
        $c = $this->engine()->settle($cash, $ledger, Period::of(2026, 3));

        // The cash basis has deducted one month less by March, so its base and
        // therefore its cumulative tax are higher.
        self::assertTrue($c->pit->taxYearToDate->greaterThan($a->pit->taxYearToDate));
        // And every report says which basis produced it.
        self::assertNotEmpty(array_filter(
            $c->notes,
            static fn (string $n): bool => str_contains($n, 'kasowe'),
        ));
    }

    public function test_every_report_carries_its_rate_sources_and_the_calculation_disclaimer(): void
    {
        $report = $this->engine()->settle(
            $this->lumpSumProfile(),
            $this->ledgerOf([1 => '10000.00'], VatStatus::ExemptBySize),
            Period::of(2026, 1),
        );

        $json = $report->jsonSerialize();
        self::assertArrayHasKey('rate_sources', $json);
        self::assertArrayHasKey('zus_social', $json['rate_sources']);
        self::assertStringContainsString('WYLICZENIE, nie deklaracja', $json['disclaimer']);
    }

    public function test_deadlines_move_off_weekends_and_holidays(): void
    {
        $report = $this->engine()->settle(
            $this->lumpSumProfile(),
            $this->ledgerOf(array_fill(1, 8, '10000.00'), VatStatus::ExemptBySize),
            Period::of(2026, 8),
        );

        // 20 September 2026 is a Sunday.
        self::assertSame('2026-09-20', $report->deadlines['zus']['statutory']->format('Y-m-d'));
        self::assertSame('2026-09-21', $report->deadlines['zus']['date']->format('Y-m-d'));
        self::assertTrue($report->deadlines['zus']['shifted']);
    }

    public function test_a_vat_registered_taxpayer_gets_a_vat_deadline_and_an_exempt_one_does_not(): void
    {
        $registered = $this->engine()->settle(
            $this->lumpSumProfile(vat: VatStatus::Registered),
            $this->ledgerOf([1 => '10000.00'], VatStatus::Registered),
            Period::of(2026, 1),
        );
        $exempt = $this->engine()->settle(
            $this->lumpSumProfile(),
            $this->ledgerOf([1 => '10000.00'], VatStatus::ExemptBySize),
            Period::of(2026, 1),
        );

        self::assertArrayHasKey('vat', $registered->deadlines);
        self::assertArrayNotHasKey('vat', $exempt->deadlines);
    }
}
