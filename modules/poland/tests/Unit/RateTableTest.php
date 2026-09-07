<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Domain\Period;
use Poland\Rates\MissingRateException;
use Poland\Rates\RateRepository;
use Poland\Rates\RateTable;

final class RateTableTest extends TestCase
{
    private function rates(): RateRepository
    {
        return RateRepository::default();
    }

    public function test_it_refuses_to_extrapolate_into_an_unconfigured_year(): void
    {
        // The rule this protects: carrying last year's ZUS base into a new
        // January produces a number that is wrong, plausible and internally
        // consistent. Refusing is the only safe answer.
        $this->expectException(MissingRateException::class);
        $this->rates()->zusSocial()->for(Period::of(2028, 1));
    }

    public function test_the_health_contribution_year_runs_february_to_january(): void
    {
        $table = $this->rates()->zusHealth();

        // January 2026 is still settled on the 2025/2026 contribution year.
        self::assertSame('2025/2026', $table->for(Period::of(2026, 1))->get('contribution_year'));
        self::assertSame('2026/2027', $table->for(Period::of(2026, 2))->get('contribution_year'));
        self::assertSame('2026/2027', $table->for(Period::of(2027, 1))->get('contribution_year'));
    }

    public function test_january_and_february_2026_use_different_lump_sum_amounts(): void
    {
        $table = $this->rates()->zusHealth();

        $january = $table->for(Period::of(2026, 1))->get('lump_sum_bands');
        $february = $table->for(Period::of(2026, 2))->get('lump_sum_bands');

        self::assertSame('461.66', $january[0]['monthly']);
        self::assertSame('498.35', $february[0]['monthly']);
    }

    public function test_published_lump_sum_amounts_match_their_stated_formula(): void
    {
        // Each band is 9% of 60% / 100% / 180% of the reference wage. If the
        // amount and the reference ever disagree, one of them was typed wrong.
        foreach ($this->rates()->zusHealth()->versions() as $version) {
            $reference = \Poland\Domain\Money::parse((string) $version->get('lump_sum_reference_wage'));
            foreach ((array) $version->get('lump_sum_bands') as $band) {
                $expected = $reference
                    ->times((int) $band['base_percent_of_reference'] / 100)
                    ->times(0.09);
                self::assertSame(
                    \Poland\Domain\Money::parse((string) $band['monthly'])->grosze,
                    $expected->grosze,
                    sprintf(
                        'Band %d%% of contribution year %s does not match 9%% of its stated base.',
                        (int) $band['base_percent_of_reference'],
                        (string) $version->get('contribution_year'),
                    ),
                );
            }
        }
    }

    public function test_published_social_bases_match_their_stated_derivation(): void
    {
        foreach ($this->rates()->zusSocial()->versions() as $version) {
            self::assertSame(
                $version->money('full_base')->grosze,
                $version->money('forecast_average_wage')->times(0.60)->grosze,
                'Full ZUS base must be 60% of the forecast average wage.',
            );
            self::assertSame(
                $version->money('preferential_base')->grosze,
                $version->money('minimum_wage')->times(0.30)->grosze,
                'Preferential ZUS base must be 30% of the minimum wage.',
            );
        }
    }

    public function test_minimum_health_contribution_matches_nine_percent_of_the_minimum_wage(): void
    {
        foreach ($this->rates()->zusHealth()->versions() as $version) {
            self::assertSame(
                $version->money('minimum_monthly')->grosze,
                $version->money('minimum_base')->times(0.09)->grosze,
            );
        }
    }

    public function test_overlapping_versions_are_rejected_at_construction(): void
    {
        $this->expectExceptionMessageMatches('/overlap/');

        new RateTable('test', ['versions' => [
            ['effective_from' => '2026-01', 'effective_to' => '2026-12'],
            ['effective_from' => '2026-06', 'effective_to' => '2027-05'],
        ]]);
    }

    public function test_an_open_ended_version_may_not_be_followed_by_another(): void
    {
        $this->expectExceptionMessageMatches('/open-ended/');

        new RateTable('test', ['versions' => [
            ['effective_from' => '2026-01', 'effective_to' => null],
            ['effective_from' => '2027-01', 'effective_to' => null],
        ]]);
    }

    public function test_every_shipped_version_names_its_source(): void
    {
        foreach (RateRepository::TABLES as $name) {
            $table = $this->rates()->table($name);
            foreach ($table->versions() as $version) {
                self::assertNotSame(
                    'unspecified',
                    $version->source(),
                    sprintf('Rate table "%s" has a version with no source.', $name),
                );
                self::assertNotNull(
                    $version->verifiedOn(),
                    sprintf('Rate table "%s" has a version with no verification date.', $name),
                );
            }
        }
    }

    public function test_vat_exemption_limit_rises_to_240000_in_2026(): void
    {
        $table = $this->rates()->vat();
        self::assertSame(20000000, $table->for(Period::of(2025, 12))->money('exemption_limit')->grosze);
        self::assertSame(24000000, $table->for(Period::of(2026, 1))->money('exemption_limit')->grosze);
    }
}
