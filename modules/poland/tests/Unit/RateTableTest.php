<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Domain\Period;
use Poland\Rates\MissingRateException;
use Poland\Rates\RateProvenance;
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

    /** A minimal well-formed version, so structural tests are not about provenance. */
    private function fixture(string $from, ?string $to, string $version = '1'): array
    {
        return [
            'version' => $version,
            'effective_from' => $from,
            'effective_to' => $to,
            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'fixture',
                'official_source_url' => 'https://example.invalid/fixture',
                'checked_on' => '2026-09-07',
                'checked_by' => 'test',
            ],
        ];
    }

    public function test_overlapping_versions_are_rejected_at_construction(): void
    {
        $this->expectExceptionMessageMatches('/overlap/');

        new RateTable('test', ['versions' => [
            $this->fixture('2026-01', '2026-12', 'a'),
            $this->fixture('2026-06', '2027-05', 'b'),
        ]]);
    }

    public function test_an_open_ended_version_may_not_be_followed_by_another(): void
    {
        $this->expectExceptionMessageMatches('/open-ended/');

        new RateTable('test', ['versions' => [
            $this->fixture('2026-01', null, 'a'),
            $this->fixture('2027-01', null, 'b'),
        ]]);
    }

    public function test_a_version_without_provenance_is_rejected(): void
    {
        // A rate nobody can trace is a rate nobody can defend, so the table
        // refuses to load rather than serving an untraceable number.
        $this->expectExceptionMessageMatches('/provenance/');

        new RateTable('test', ['versions' => [
            ['version' => '1', 'effective_from' => '2026-01', 'effective_to' => null],
        ]]);
    }

    public function test_a_version_without_a_version_identifier_is_rejected(): void
    {
        $this->expectExceptionMessageMatches('/missing "version"/');

        new RateTable('test', ['versions' => [
            ['effective_from' => '2026-01', 'effective_to' => null, 'provenance' => []],
        ]]);
    }

    public function test_provenance_that_is_not_official_must_say_where_to_verify_it(): void
    {
        // An unverified rate with no route to verification never gets verified.
        $this->expectExceptionMessageMatches('/WHERE it has to be confirmed/');

        RateProvenance::fromArray([
            'status' => 'secondary',
            'source_document' => 'trade press',
            'checked_on' => '2026-09-07',
            'checked_by' => 'test',
        ]);
    }

    public function test_provenance_marked_official_must_carry_the_official_url(): void
    {
        $this->expectExceptionMessageMatches('/must carry the URL/');

        RateProvenance::fromArray([
            'status' => 'official',
            'source_document' => 'Dz.U. 2025 poz. 1',
            'checked_on' => '2026-09-07',
            'checked_by' => 'test',
        ]);
    }

    public function test_provenance_fields_are_read_in_the_right_order(): void
    {
        // Eight string-ish constructor parameters; a positional call survives a
        // reordering silently, so the mapping itself is pinned.
        $provenance = RateProvenance::fromArray([
            'status' => 'official',
            'source_document' => 'DOC',
            'source_url' => 'https://source.invalid/a',
            'official_source_url' => 'https://official.invalid/b',
            'published_on' => '2025-06-24',
            'checked_on' => '2026-09-07',
            'checked_by' => 'WHO',
            'notes' => 'NOTE',
        ]);

        self::assertSame('DOC', $provenance->sourceDocument);
        self::assertSame('https://source.invalid/a', $provenance->sourceUrl);
        self::assertSame('https://official.invalid/b', $provenance->officialSourceUrl);
        self::assertSame('2025-06-24', $provenance->publishedOn);
        self::assertSame('2026-09-07', $provenance->checkedOn);
        self::assertSame('WHO', $provenance->checkedBy);
        self::assertSame('NOTE', $provenance->notes);
    }

    public function test_every_shipped_version_carries_complete_provenance(): void
    {
        foreach (RateRepository::TABLES as $name) {
            foreach ($this->rates()->table($name)->versions() as $version) {
                $where = sprintf('%s v%s', $name, $version->version);

                self::assertNotSame('', trim($version->version), "{$where}: empty version identifier");
                self::assertNotSame('', trim($version->provenance->sourceDocument), "{$where}: no source document");
                self::assertNotSame('', trim($version->provenance->checkedOn), "{$where}: no check date");
                self::assertNotSame('', trim($version->provenance->checkedBy), "{$where}: no checker");

                if (! $version->provenance->status->fitForFiling()) {
                    self::assertNotSame(
                        '',
                        trim($version->provenance->officialSourceUrl),
                        "{$where}: not official, and no official source named to verify it against",
                    );
                }
            }
        }
    }

    public function test_the_shipped_tables_are_honest_about_not_being_officially_verified(): void
    {
        // This test is expected to FAIL the day somebody verifies the rates
        // against official sources and flips the statuses. That is the point:
        // it makes the transition deliberate rather than accidental, and the
        // failure message says exactly what to do.
        $notOfficial = [];
        foreach (RateRepository::TABLES as $name) {
            foreach ($this->rates()->table($name)->versions() as $version) {
                if (! $version->provenance->status->fitForFiling()) {
                    $notOfficial[] = $name.' v'.$version->version;
                }
            }
        }

        self::assertNotEmpty(
            $notOfficial,
            'Every shipped rate version is now marked official. If that is genuinely true, '
            .'delete this test and update docs/OPEN_ITEMS.md — do not just re-run it.',
        );
    }

    public function test_values_carry_a_stated_meaning(): void
    {
        // A number whose meaning lives only in a developer's head cannot be
        // checked by the accountant who has to sign it off.
        foreach (['zus_social', 'zus_health', 'pit', 'vat'] as $name) {
            foreach ($this->rates()->table($name)->versions() as $version) {
                self::assertNotEmpty(
                    $version->meanings,
                    sprintf('%s v%s has no meanings for its values.', $name, $version->version),
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
