<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Calculators\PitCalculator;
use Poland\Calculators\PitInput;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;
use Poland\Rates\RateRepository;

final class PitCalculatorTest extends TestCase
{
    private PitCalculator $pit;

    protected function setUp(): void
    {
        $this->pit = new PitCalculator(RateRepository::default());
    }

    private function profile(PitRegime $regime, ?float $lumpSumRate = null): TaxProfile
    {
        return new TaxProfile(
            name: 'Test',
            pitRegime: $regime,
            vatStatus: VatStatus::Registered,
            zusScheme: ZusScheme::Full,
            businessStartedAt: Period::of(2019, 1),
            lumpSumRate: $lumpSumRate,
        );
    }

    private function input(
        string $revenue,
        string $costs = '0.00',
        string $social = '0.00',
        string $health = '0.00',
        string $advances = '0.00',
        bool $costsRecorded = true,
    ): PitInput {
        return new PitInput(
            Money::parse($revenue),
            Money::parse($costs),
            Money::parse($social),
            Money::parse($health),
            Money::parse($advances),
            [],
            $costsRecorded,
        );
    }

    public function test_lump_sum_taxes_revenue_less_social_and_half_the_health_contribution(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::LumpSum, 0.085),
            Period::of(2026, 1),
            $this->input('100000.00', social: '10000.00', health: '4000.00'),
        );

        // 100 000 − 10 000 − 2 000 = 88 000; x 8,5% = 7 480
        self::assertSame(8800000, $result->taxBaseYearToDate->grosze);
        self::assertSame(748000, $result->taxYearToDate->grosze);
        self::assertSame(748000, $result->advanceDue->grosze);
    }

    public function test_lump_sum_subtracts_advances_already_due(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::LumpSum, 0.085),
            Period::of(2026, 8),
            $this->input('100000.00', social: '10000.00', health: '4000.00', advances: '6000.00'),
        );

        self::assertSame(148000, $result->advanceDue->grosze);
    }

    public function test_lump_sum_never_returns_a_negative_advance(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::LumpSum, 0.085),
            Period::of(2026, 8),
            $this->input('100000.00', social: '10000.00', health: '4000.00', advances: '9000.00'),
        );

        self::assertTrue($result->advanceDue->isZero());
        self::assertNotEmpty($result->notes);
    }

    public function test_lump_sum_apportions_deductions_across_several_rates(): void
    {
        $input = new PitInput(
            Money::parse('100000.00'),
            Money::zero(),
            Money::parse('10000.00'),
            Money::zero(),
            Money::zero(),
            ['0.03' => Money::parse('60000.00'), '0.085' => Money::parse('40000.00')],
        );

        $result = $this->pit->calculate($this->profile(PitRegime::LumpSum, 0.03), Period::of(2026, 6), $input);

        // Deductions split 60/40: bases 54 000 and 36 000.
        // 54 000 x 3% = 1 620; 36 000 x 8,5% = 3 060; total 4 680.
        self::assertSame(468000, $result->taxYearToDate->grosze);
    }

    public function test_scale_applies_the_tax_reducing_amount(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::Scale),
            Period::of(2026, 6),
            $this->input('100000.00', costs: '40000.00', social: '10000.00'),
        );

        // Base 50 000; 12% = 6 000 − 3 600 = 2 400.
        self::assertSame(5000000, $result->taxBaseYearToDate->grosze);
        self::assertSame(240000, $result->taxYearToDate->grosze);
    }

    public function test_scale_income_inside_the_tax_free_allowance_pays_nothing(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::Scale),
            Period::of(2026, 6),
            $this->input('30000.00', costs: '5000.00'),
        );

        self::assertTrue($result->taxYearToDate->isZero());
        self::assertTrue($result->advanceDue->isZero());
    }

    public function test_scale_crosses_into_the_thirty_two_percent_band(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::Scale),
            Period::of(2026, 11),
            $this->input('200000.00'),
        );

        // 120 000 x 12% − 3 600 = 10 800; (200 000 − 120 000) x 32% = 25 600.
        self::assertSame(3640000, $result->taxYearToDate->grosze);
    }

    public function test_scale_grants_no_health_deduction(): void
    {
        $withHealth = $this->pit->calculate(
            $this->profile(PitRegime::Scale),
            Period::of(2026, 6),
            $this->input('100000.00', social: '10000.00', health: '8000.00'),
        );
        $withoutHealth = $this->pit->calculate(
            $this->profile(PitRegime::Scale),
            Period::of(2026, 6),
            $this->input('100000.00', social: '10000.00'),
        );

        self::assertSame($withoutHealth->taxYearToDate->grosze, $withHealth->taxYearToDate->grosze);
    }

    public function test_flat_tax_is_nineteen_percent_after_social_and_capped_health(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::Flat),
            Period::of(2026, 6),
            $this->input('200000.00', costs: '50000.00', social: '10000.00', health: '9000.00'),
        );

        // 200 000 − 50 000 − 10 000 − 9 000 = 131 000; x 19% = 24 890.
        self::assertSame(13100000, $result->taxBaseYearToDate->grosze);
        self::assertSame(2489000, $result->taxYearToDate->grosze);
    }

    public function test_flat_tax_health_deduction_stops_at_the_annual_limit(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::Flat),
            Period::of(2026, 12),
            $this->input('300000.00', health: '20000.00'),
        );

        // The 2026 cap is 14 100, not the 20 000 actually paid.
        self::assertSame(28590000, $result->taxBaseYearToDate->grosze);
    }

    public function test_income_regimes_flag_a_missing_cost_register_as_an_upper_bound(): void
    {
        foreach ([PitRegime::Scale, PitRegime::Flat] as $regime) {
            $result = $this->pit->calculate(
                $this->profile($regime),
                Period::of(2026, 6),
                $this->input('100000.00', costsRecorded: false),
            );

            self::assertTrue($result->isEstimate, $regime->value.' should be flagged as an estimate');
            self::assertNotEmpty(array_filter(
                $result->notes,
                static fn (string $n): bool => str_contains($n, 'BRAK EWIDENCJI KOSZTÓW'),
            ));
        }
    }

    public function test_lump_sum_from_a_cash_register_alone_is_not_an_estimate(): void
    {
        // The point of the whole design: ryczałt taxes revenue, so sales data
        // alone is a complete answer, not a bounded one.
        $result = $this->pit->calculate(
            $this->profile(PitRegime::LumpSum, 0.085),
            Period::of(2026, 6),
            $this->input('100000.00', social: '10000.00', health: '4000.00', costsRecorded: false),
        );

        self::assertFalse($result->isEstimate);
    }

    public function test_it_flags_the_solidarity_levy_threshold(): void
    {
        $result = $this->pit->calculate(
            $this->profile(PitRegime::Scale),
            Period::of(2026, 12),
            $this->input('1500000.00'),
        );

        self::assertNotEmpty(array_filter(
            $result->notes,
            static fn (string $n): bool => str_contains($n, 'daniny solidarnościowej'),
        ));
    }
}
