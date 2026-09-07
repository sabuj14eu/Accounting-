<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Calculators\ZusCalculator;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;
use Poland\Rates\RateRepository;

final class ZusCalculatorTest extends TestCase
{
    private ZusCalculator $zus;

    protected function setUp(): void
    {
        $this->zus = new ZusCalculator(RateRepository::default());
    }

    private function profile(
        ZusScheme $scheme = ZusScheme::Full,
        PitRegime $regime = PitRegime::LumpSum,
        bool $sickness = true,
        ?Period $started = null,
        int $startDay = 1,
    ): TaxProfile {
        return new TaxProfile(
            name: 'Test',
            pitRegime: $regime,
            vatStatus: VatStatus::Registered,
            zusScheme: $scheme,
            businessStartedAt: $started ?? Period::of(2019, 1),
            businessStartedOnDay: $startDay,
            lumpSumRate: $regime === PitRegime::LumpSum ? 0.085 : null,
            sicknessInsurance: $sickness,
        );
    }

    public function test_full_social_contributions_2026_match_the_published_total(): void
    {
        // ZUS publishes 1 926,76 zł for 2026 with voluntary sickness cover and
        // the Labour Fund. Reproducing that exact total from the components is
        // the check that every rate and the base are right.
        $result = $this->zus->calculate($this->profile(), Period::of(2026, 6), null, Money::parse('10000.00'));

        self::assertSame('5652.00', $result->contributionBase->jsonSerialize());
        self::assertSame(192676, $result->socialTotal->grosze);
    }

    public function test_full_social_contributions_2025_match_the_published_total(): void
    {
        $result = $this->zus->calculate($this->profile(), Period::of(2025, 6), null, Money::parse('10000.00'));

        self::assertSame('5203.80', $result->contributionBase->jsonSerialize());
        self::assertSame(177396, $result->socialTotal->grosze);
    }

    public function test_preferential_contributions_2026_match_the_published_total(): void
    {
        $result = $this->zus->calculate(
            $this->profile(ZusScheme::Preferential, started: Period::of(2025, 6)),
            Period::of(2026, 6),
            null,
            Money::parse('10000.00'),
        );

        self::assertSame('1441.80', $result->contributionBase->jsonSerialize());
        self::assertSame(45618, $result->socialTotal->grosze);
    }

    public function test_preferential_contributions_without_sickness_cover(): void
    {
        $result = $this->zus->calculate(
            $this->profile(ZusScheme::Preferential, sickness: false, started: Period::of(2025, 6)),
            Period::of(2026, 6),
            null,
            Money::parse('10000.00'),
        );

        self::assertSame(42086, $result->socialTotal->grosze);
    }

    public function test_preferential_scheme_pays_no_labour_fund(): void
    {
        // Not a special case for the preferential scheme: the Labour Fund is
        // simply not due below the minimum wage, and 30% of it never reaches it.
        $result = $this->zus->calculate(
            $this->profile(ZusScheme::Preferential, started: Period::of(2025, 6)),
            Period::of(2026, 6),
            null,
            Money::parse('10000.00'),
        );

        self::assertArrayNotHasKey('Fundusz Pracy i FS', $result->socialComponents);
    }

    public function test_ulga_na_start_pays_health_only(): void
    {
        $result = $this->zus->calculate(
            $this->profile(ZusScheme::UlgaNaStart, started: Period::of(2026, 4)),
            Period::of(2026, 6),
            null,
            Money::parse('10000.00'),
        );

        self::assertTrue($result->socialTotal->isZero());
        self::assertSame(49835, $result->health->grosze);
        self::assertSame(49835, $result->total->grosze);
    }

    public function test_an_expired_scheme_is_reported_and_not_silently_changed(): void
    {
        $result = $this->zus->calculate(
            $this->profile(ZusScheme::Preferential, started: Period::of(2023, 1)),
            Period::of(2026, 6),
            null,
            Money::parse('10000.00'),
        );

        // Still computed on the configured scheme...
        self::assertSame(45618, $result->socialTotal->grosze);
        // ...but the taxpayer is told it has run out.
        self::assertNotEmpty(array_filter(
            $result->notes,
            static fn (string $n): bool => str_contains($n, 'SCHEMAT WYGASŁ'),
        ));
    }

    public function test_lump_sum_health_bands_step_with_year_to_date_revenue(): void
    {
        $june = Period::of(2026, 6);

        $low = $this->zus->calculate($this->profile(), $june, null, Money::parse('50000.00'));
        $mid = $this->zus->calculate($this->profile(), $june, null, Money::parse('150000.00'));
        $high = $this->zus->calculate($this->profile(), $june, null, Money::parse('500000.00'));

        self::assertSame(49835, $low->health->grosze);
        self::assertSame(83058, $mid->health->grosze);
        self::assertSame(149504, $high->health->grosze);
    }

    public function test_january_uses_the_previous_contribution_year_amounts(): void
    {
        // The trap: January 2026 is still in the 2025/2026 contribution year.
        $january = $this->zus->calculate($this->profile(), Period::of(2026, 1), null, Money::parse('50000.00'));
        $february = $this->zus->calculate($this->profile(), Period::of(2026, 2), null, Money::parse('50000.00'));

        self::assertSame(46166, $january->health->grosze);
        self::assertSame(49835, $february->health->grosze);
    }

    public function test_social_contributions_paid_lower_the_band_reference(): void
    {
        // Revenue just over 60 000, but net of social contributions it is under,
        // so the lower band applies (art. 81 ust. 2g).
        $result = $this->zus->calculate(
            $this->profile(),
            Period::of(2026, 6),
            null,
            Money::parse('61000.00'),
            Money::parse('2000.00'),
        );

        self::assertSame(49835, $result->health->grosze);
    }

    public function test_scale_health_is_nine_percent_of_the_previous_months_income(): void
    {
        $result = $this->zus->calculate(
            $this->profile(regime: PitRegime::Scale),
            Period::of(2026, 6),
            Money::parse('20000.00'),
        );

        self::assertSame(180000, $result->health->grosze);
    }

    public function test_flat_health_is_four_point_nine_percent_of_the_previous_months_income(): void
    {
        $result = $this->zus->calculate(
            $this->profile(regime: PitRegime::Flat),
            Period::of(2026, 6),
            Money::parse('20000.00'),
        );

        self::assertSame(98000, $result->health->grosze);
    }

    public function test_health_never_falls_below_the_statutory_minimum(): void
    {
        $result = $this->zus->calculate(
            $this->profile(regime: PitRegime::Scale),
            Period::of(2026, 6),
            Money::parse('100.00'),
        );

        self::assertSame(43254, $result->health->grosze);
    }

    public function test_a_loss_month_still_pays_the_minimum_health_contribution(): void
    {
        $result = $this->zus->calculate(
            $this->profile(regime: PitRegime::Scale),
            Period::of(2026, 6),
            Money::parse('-8000.00'),
        );

        self::assertSame(43254, $result->health->grosze);
    }

    public function test_unknown_previous_income_yields_the_minimum_and_says_so(): void
    {
        $result = $this->zus->calculate($this->profile(regime: PitRegime::Scale), Period::of(2026, 6), null);

        self::assertSame(43254, $result->health->grosze);
        self::assertNotEmpty(array_filter(
            $result->notes,
            static fn (string $n): bool => str_contains($n, 'DOCHÓD ZA MIESIĄC POPRZEDNI NIEZNANY'),
        ));
    }

    public function test_an_incomplete_first_month_prorates_social_but_not_health(): void
    {
        // Started 16 June 2026: 15 of 30 days insured.
        $result = $this->zus->calculate(
            $this->profile(started: Period::of(2026, 6), startDay: 16),
            Period::of(2026, 6),
            null,
            Money::parse('10000.00'),
        );

        self::assertSame(282600, $result->contributionBase->grosze, 'Half of 5 652,00 zł');
        // Health is indivisible and stays whole.
        self::assertSame(49835, $result->health->grosze);
        self::assertNotEmpty(array_filter(
            $result->notes,
            static fn (string $n): bool => str_contains($n, 'niepodzielna'),
        ));
    }

    public function test_a_prorated_full_base_still_owes_the_labour_fund(): void
    {
        // The Labour Fund test is against the full-month base, not the
        // shortened one, so a mid-month start does not accidentally drop it.
        $result = $this->zus->calculate(
            $this->profile(started: Period::of(2026, 6), startDay: 16),
            Period::of(2026, 6),
            null,
            Money::parse('10000.00'),
        );

        self::assertArrayHasKey('Fundusz Pracy i FS', $result->socialComponents);
    }
}
