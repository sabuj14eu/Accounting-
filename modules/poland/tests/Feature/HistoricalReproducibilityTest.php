<?php

declare(strict_types=1);

namespace Poland\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\FiscalSalesReport;
use Poland\Domain\Ledger;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;
use Poland\Rates\MissingRateException;
use Poland\Rates\RateRepository;
use Poland\Reporting\SettlementEngine;

/**
 * A report for an old month must use the rules that were effective for that
 * month. Never today's.
 *
 * These are regression tests in the strict sense: each one pins a specific way
 * the engine could start quietly producing a different number for a month that
 * was already settled and possibly already paid.
 */
final class HistoricalReproducibilityTest extends TestCase
{
    private function profile(
        PitRegime $regime = PitRegime::LumpSum,
        ZusScheme $zus = ZusScheme::Full,
        ?Period $started = null,
        int $startDay = 1,
    ): TaxProfile {
        return new TaxProfile(
            name: 'Test',
            pitRegime: $regime,
            vatStatus: VatStatus::ExemptBySize,
            zusScheme: $zus,
            businessStartedAt: $started ?? Period::of(2020, 1),
            businessStartedOnDay: $startDay,
            lumpSumRate: $regime === PitRegime::LumpSum ? 0.085 : null,
        );
    }

    private function ledgerFor(array $months, string $gross = '20000.00'): Ledger
    {
        $ledger = new Ledger();
        foreach ($months as [$y, $m]) {
            $ledger->recordSales(FiscalSalesReport::exempt(Period::of($y, $m), $gross));
        }

        return $ledger;
    }

    public function test_january_2026_uses_the_2025_2026_contribution_year(): void
    {
        // The health contribution year runs 1 Feb - 31 Jan. January 2026 belongs
        // to the year that started in February 2025, so it uses 461,66 - NOT the
        // 498,35 that takes effect a month later.
        $run = SettlementEngine::withDefaultRates()->replayYear(
            $this->profile(),
            $this->ledgerFor([[2026, 1]]),
            Period::of(2026, 1),
        );

        self::assertSame(46166, $run['2026-01']['zus']->health->grosze);
        self::assertSame(
            '2025/2026',
            RateRepository::default()->zusHealth()->for(Period::of(2026, 1))->get('contribution_year'),
        );
    }

    public function test_february_2026_switches_to_the_new_contribution_year(): void
    {
        $run = SettlementEngine::withDefaultRates()->replayYear(
            $this->profile(),
            $this->ledgerFor([[2026, 1], [2026, 2]]),
            Period::of(2026, 2),
        );

        self::assertSame(46166, $run['2026-01']['zus']->health->grosze, 'January stays on the old year');
        self::assertSame(49835, $run['2026-02']['zus']->health->grosze, 'February moves to the new one');
    }

    public function test_a_2025_month_uses_2025_social_bases_not_2026(): void
    {
        $engine = SettlementEngine::withDefaultRates();

        $y2025 = $engine->replayYear($this->profile(), $this->ledgerFor([[2025, 6]]), Period::of(2025, 6));
        $y2026 = $engine->replayYear($this->profile(), $this->ledgerFor([[2026, 6]]), Period::of(2026, 6));

        self::assertSame(520380, $y2025['2025-06']['zus']->contributionBase->grosze, '2025 base 5 203,80');
        self::assertSame(177396, $y2025['2025-06']['zus']->socialTotal->grosze, '2025 total 1 773,96');

        self::assertSame(565200, $y2026['2026-06']['zus']->contributionBase->grosze, '2026 base 5 652,00');
        self::assertSame(192676, $y2026['2026-06']['zus']->socialTotal->grosze, '2026 total 1 926,76');
    }

    public function test_the_2025_vat_exemption_limit_is_not_replaced_by_the_2026_one(): void
    {
        $rates = RateRepository::default();

        self::assertSame(20000000, $rates->vat()->for(Period::of(2025, 12))->money('exemption_limit')->grosze);
        self::assertSame(24000000, $rates->vat()->for(Period::of(2026, 1))->money('exemption_limit')->grosze);
    }

    public function test_settling_the_same_historical_month_twice_gives_the_same_answer(): void
    {
        $profile = $this->profile();
        $ledger = $this->ledgerFor([[2025, 1], [2025, 2], [2025, 3]]);

        $first = SettlementEngine::withDefaultRates()->settle($profile, $ledger, Period::of(2025, 3));
        $second = SettlementEngine::withDefaultRates()->settle($profile, $ledger, Period::of(2025, 3));

        self::assertSame($first->totalDue->grosze, $second->totalDue->grosze);
        self::assertSame($first->zus->total->grosze, $second->zus->total->grosze);
        self::assertSame($first->pit->advanceDue->grosze, $second->pit->advanceDue->grosze);
        self::assertSame($first->rateSources, $second->rateSources);
    }

    public function test_every_settlement_records_which_rate_versions_produced_it(): void
    {
        $report = SettlementEngine::withDefaultRates()->settle(
            $this->profile(),
            $this->ledgerFor([[2025, 6]]),
            Period::of(2025, 6),
        );

        self::assertSame('2025.1', $report->rateProvenance['zus_social']['version']);
        self::assertSame('2025-02.1', $report->rateProvenance['zus_health']['version']);
        self::assertSame('2025.1', $report->rateProvenance['vat']['version']);
    }

    public function test_an_incomplete_first_month_prorates_social_contributions(): void
    {
        // Started 16 June 2026: 15 of 30 days insured, so half the base.
        $run = SettlementEngine::withDefaultRates()->replayYear(
            $this->profile(started: Period::of(2026, 6), startDay: 16),
            $this->ledgerFor([[2026, 6]]),
            Period::of(2026, 6),
        );

        self::assertSame(282600, $run['2026-06']['zus']->contributionBase->grosze, 'half of 5 652,00');
        self::assertTrue($run['2026-06']['zus']->socialTotal->lessThan(Money::parse('1926.76')));
    }

    public function test_the_health_contribution_is_not_prorated_in_an_incomplete_month(): void
    {
        // It is indivisible and paid whole, even for one day of activity.
        $partial = SettlementEngine::withDefaultRates()->replayYear(
            $this->profile(started: Period::of(2026, 6), startDay: 16),
            $this->ledgerFor([[2026, 6]]),
            Period::of(2026, 6),
        );
        $full = SettlementEngine::withDefaultRates()->replayYear(
            $this->profile(started: Period::of(2026, 6)),
            $this->ledgerFor([[2026, 6]]),
            Period::of(2026, 6),
        );

        self::assertSame(
            $full['2026-06']['zus']->health->grosze,
            $partial['2026-06']['zus']->health->grosze,
            'Health is indivisible: a half month pays it whole.',
        );
    }

    public function test_a_month_beyond_the_configured_rates_is_refused_not_extrapolated(): void
    {
        $this->expectException(MissingRateException::class);

        SettlementEngine::withDefaultRates()->settle(
            $this->profile(),
            $this->ledgerFor([[2028, 3]]),
            Period::of(2028, 3),
        );
    }

    public function test_the_refusal_names_the_period_and_the_table(): void
    {
        try {
            SettlementEngine::withDefaultRates()->settle(
                $this->profile(),
                $this->ledgerFor([[2028, 3]]),
                Period::of(2028, 3),
            );
            self::fail('Expected a refusal.');
        } catch (MissingRateException $e) {
            self::assertStringContainsString('2028-03', $e->getMessage());
            self::assertStringContainsString('will not', $e->getMessage());
        }
    }

    public function test_no_rate_table_silently_extends_past_its_end(): void
    {
        // The specific failure this guards: a table whose last version is
        // open-ended would price 2030 with 2026 numbers and never say so.
        $rates = RateRepository::default();

        foreach (['zus_social', 'zus_health'] as $name) {
            self::assertNotNull(
                $rates->table($name)->coveredUntil(),
                sprintf(
                    'Rate table "%s" ends with an open-ended version, so it would price any future '
                    .'month with today\'s numbers instead of refusing.',
                    $name,
                ),
            );
        }
    }

    public function test_a_vat_payer_is_taxed_on_net_revenue_in_every_year(): void
    {
        $registered = new TaxProfile(
            name: 'Test',
            pitRegime: PitRegime::LumpSum,
            vatStatus: VatStatus::Registered,
            zusScheme: ZusScheme::Full,
            businessStartedAt: Period::of(2020, 1),
            lumpSumRate: 0.085,
        );

        foreach ([[2025, 6], [2026, 6]] as [$y, $m]) {
            $ledger = new Ledger();
            $ledger->recordSales(FiscalSalesReport::singleRate(Period::of($y, $m), '12300.00', '0.23'));

            $report = SettlementEngine::withDefaultRates()->settle($registered, $ledger, Period::of($y, $m));

            self::assertSame(
                1000000,
                $report->revenueForIncomeTax->grosze,
                sprintf('%d-%02d: revenue must be net (10 000), not gross (12 300)', $y, $m),
            );
        }
    }
}
