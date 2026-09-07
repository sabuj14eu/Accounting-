<?php

declare(strict_types=1);

namespace Poland\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Poland\Contracts\SchemaRegistry;
use Poland\Contracts\SchemaVersion;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\FiscalSalesReport;
use Poland\Domain\Ledger;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;
use Poland\Rates\RateRepository;
use Poland\Rates\UnverifiedRateException;
use Poland\Reporting\FilingChannel;
use Poland\Reporting\SettlementEngine;
use Poland\Reporting\SettlementStage;

/**
 * Calculation, preparation and filing are three different claims about the
 * world. These tests hold the line between them.
 */
final class FilingLifecycleTest extends TestCase
{
    private function profile(): TaxProfile
    {
        return new TaxProfile(
            name: 'Sklep',
            pitRegime: PitRegime::LumpSum,
            vatStatus: VatStatus::ExemptBySize,
            zusScheme: ZusScheme::Full,
            businessStartedAt: Period::of(2021, 3),
            lumpSumRate: 0.03,
        );
    }

    private function ledger(): Ledger
    {
        $ledger = new Ledger();
        foreach (range(1, 8) as $m) {
            $ledger->recordSales(FiscalSalesReport::exempt(Period::of(2026, $m), '18000.00'));
        }

        return $ledger;
    }

    public function test_the_engine_refuses_to_settle_on_unverified_rates_when_required(): void
    {
        // This is the production gate: rates read from the trade press must not
        // be able to produce an amount somebody is told to pay.
        $engine = SettlementEngine::withDefaultRates(requireOfficialRates: true);

        $this->expectException(UnverifiedRateException::class);
        $engine->settle($this->profile(), $this->ledger(), Period::of(2026, 8));
    }

    public function test_the_refusal_names_the_offending_tables_and_how_to_fix_it(): void
    {
        $engine = SettlementEngine::withDefaultRates(requireOfficialRates: true);

        try {
            $engine->settle($this->profile(), $this->ledger(), Period::of(2026, 8));
            self::fail('Expected a refusal.');
        } catch (UnverifiedRateException $e) {
            self::assertNotEmpty($e->offending);
            self::assertArrayHasKey('zus_social', $e->offending);
            self::assertStringContainsString('poland:rate-provenance', $e->getMessage());
        }
    }

    public function test_without_the_requirement_it_settles_but_warns_loudly(): void
    {
        $report = SettlementEngine::withDefaultRates()->settle(
            $this->profile(),
            $this->ledger(),
            Period::of(2026, 8),
        );

        self::assertFalse($report->ratesFitForFiling);
        self::assertNotEmpty(array_filter(
            $report->warnings,
            static fn (string $w): bool => str_contains($w, 'STAWKI NIEZWERYFIKOWANE URZĘDOWO'),
        ));
    }

    public function test_a_report_on_unverified_rates_is_not_fit_for_filing(): void
    {
        $report = SettlementEngine::withDefaultRates()->settle(
            $this->profile(),
            $this->ledger(),
            Period::of(2026, 8),
        );

        // The arithmetic is exact — ryczałt from a complete cash register.
        self::assertFalse($report->isEstimate);
        // And it still may not be filed, for a different reason.
        self::assertFalse($report->fitForFiling());
        self::assertContains(
            'Użyte stawki nie zostały potwierdzone w źródłach urzędowych.',
            $report->blockersToFiling(),
        );
    }

    public function test_every_report_carries_the_provenance_of_the_rates_it_used(): void
    {
        $report = SettlementEngine::withDefaultRates()->settle(
            $this->profile(),
            $this->ledger(),
            Period::of(2026, 8),
        );

        self::assertArrayHasKey('zus_health', $report->rateProvenance);
        $entry = $report->rateProvenance['zus_health'];

        self::assertSame('2026-02.1', $entry['version']);
        self::assertNotSame('', $entry['provenance']->sourceDocument);
        self::assertNotSame('', $entry['provenance']->officialSourceUrl);
    }

    public function test_stages_advance_only_one_step_at_a_time(): void
    {
        self::assertTrue(SettlementStage::Calculated->canAdvanceTo(SettlementStage::Prepared));
        self::assertTrue(SettlementStage::Prepared->canAdvanceTo(SettlementStage::Filed));
        // Calculating something does not make it filed, however complete it is.
        self::assertFalse(SettlementStage::Calculated->canAdvanceTo(SettlementStage::Filed));
    }

    public function test_only_the_filed_stage_counts_as_submitted(): void
    {
        self::assertFalse(SettlementStage::Calculated->submitted());
        self::assertFalse(SettlementStage::Prepared->submitted());
        self::assertTrue(SettlementStage::Filed->submitted());
    }

    public function test_no_filing_channel_is_automated_yet_and_says_so(): void
    {
        foreach (FilingChannel::cases() as $channel) {
            self::assertFalse(
                $channel->automated(),
                sprintf(
                    'Channel %s claims to be automated. If an integration was really built, '
                    .'update this test deliberately — do not let it drift.',
                    $channel->value,
                ),
            );
        }
    }

    public function test_an_unconfigured_submitter_throws_rather_than_failing_quietly(): void
    {
        $submitter = new \Poland\Adapters\Null\UnconfiguredSubmitter(FilingChannel::Ksef);

        self::assertFalse($submitter->isConfigured());
        $this->expectExceptionMessageMatches('/nie jest zaimplementowany/');
        $submitter->submit(new \Poland\Contracts\PreparedDocument(
            FilingChannel::Ksef,
            Period::of(2026, 8),
            new SchemaVersion('FA', '(2)', Period::of(2026, 1), null),
            'application/xml',
            '<x/>',
        ));
    }

    public function test_a_missing_exchange_rate_is_refused_not_defaulted(): void
    {
        $provider = new \Poland\Adapters\Null\UnavailableExchangeRateProvider();

        self::assertFalse($provider->isAvailable());
        $this->expectExceptionMessageMatches('/wyglądającą wiarygodnie i nieprawdziwą/');
        $provider->rateFor('EUR', new \DateTimeImmutable('2026-08-15'));
    }

    public function test_schema_versions_are_chosen_by_period_not_by_newest(): void
    {
        $registry = new SchemaRegistry([
            new SchemaVersion('JPK_V7M', '(1)', Period::of(2020, 10), Period::of(2025, 12)),
            new SchemaVersion('JPK_V7M', '(2)', Period::of(2026, 1), null),
        ]);

        // A correction to an old month must be prepared under the old schema.
        self::assertSame('(1)', $registry->for('JPK_V7M', Period::of(2025, 6))->version);
        self::assertSame('(2)', $registry->for('JPK_V7M', Period::of(2026, 6))->version);
    }

    public function test_an_unregistered_schema_period_is_refused(): void
    {
        $registry = new SchemaRegistry([
            new SchemaVersion('JPK_V7M', '(2)', Period::of(2026, 1), null),
        ]);

        $this->expectExceptionMessageMatches('/No "JPK_V7M" schema version is registered/');
        $registry->for('JPK_V7M', Period::of(2024, 5));
    }

    public function test_a_document_that_could_not_be_validated_is_not_valid(): void
    {
        // "No errors recorded" and "checked and clean" are different facts.
        $unvalidated = new \Poland\Contracts\PreparedDocument(
            FilingChannel::JpkV7,
            Period::of(2026, 8),
            new SchemaVersion('JPK_V7M', '(2)', Period::of(2026, 1), null),
            'application/xml',
            '<x/>',
            [],
            [],
            validated: false,
        );

        self::assertFalse($unvalidated->isValid());
    }

    public function test_a_filing_result_without_a_reference_is_not_an_acceptance(): void
    {
        $noReference = new \Poland\Contracts\FilingResult('OK', null, new \DateTimeImmutable());
        $blankReference = new \Poland\Contracts\FilingResult('OK', '  ', new \DateTimeImmutable());
        $accepted = new \Poland\Contracts\FilingResult('OK', 'REF-1', new \DateTimeImmutable());

        self::assertFalse($noReference->accepted(), 'A 200 is not a filing.');
        self::assertFalse($blankReference->accepted());
        self::assertTrue($accepted->accepted());
    }
}
