<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\SalesReportModel;
use Poland\Laravel\Models\SettlementModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Contracts\DocumentPreparer;
use Poland\Contracts\DocumentSubmitter;
use Poland\Laravel\Models\PreparedDocumentModel;
use Poland\Reporting\FilingChannel;
use Poland\Reporting\MonthlyTaxReport;
use Poland\Reporting\SettlementEngine;
use Poland\Reporting\SettlementStage;
use RuntimeException;

/**
 * Records cash-register takings and stores the settlements computed from them.
 *
 * Two invariants are enforced here rather than left to callers:
 *  - a month's takings are recorded once, and a change is a CORRECTION that
 *    supersedes the previous report instead of overwriting it;
 *  - a settlement is a calculation until an actual submission succeeds, and
 *    only {@see self::markFiled()} may say otherwise.
 */
final class SettlementRecorder
{
    public function __construct(
        private readonly SettlementEngine $engine,
        private readonly LedgerRepository $ledgers,
        private readonly AuditRecorder $audit,
    ) {
    }

    /**
     * Record a month's takings from the fiscal cash register.
     *
     * @param array<string,Money|string> $grossByDesignation
     */
    public function recordSales(
        TaxProfileModel $profile,
        Period $period,
        array $grossByDesignation,
        ?string $registerId = null,
        ?string $reportNumber = null,
        ?string $correctionReason = null,
    ): SalesReportModel {
        return DB::transaction(function () use (
            $profile, $period, $grossByDesignation, $registerId, $reportNumber, $correctionReason
        ): SalesReportModel {
            $existing = SalesReportModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('period', $period->toString())
                ->inForce()
                ->first();

            if ($existing !== null && $correctionReason === null) {
                throw new RuntimeException(sprintf(
                    'Sprzedaż za %s została już zapisana (%s). Zmiana wymaga podania przyczyny '
                    .'korekty — poprzedni raport zostanie zachowany, nie nadpisany.',
                    $period->toString(),
                    Money::parse((string) $existing->gross_total)->format(),
                ));
            }

            $lines = [];
            $gross = Money::zero();
            $net = Money::zero();
            $vat = Money::zero();

            foreach ($grossByDesignation as $designation => $amount) {
                $line = new \Poland\Domain\SalesLine(
                    (string) $designation,
                    $amount instanceof Money ? $amount : Money::parse($amount),
                );
                $lines[] = $line;
                $gross = $gross->plus($line->gross);
                $net = $net->plus($line->net());
                $vat = $vat->plus($line->vat());
            }

            if ($lines === []) {
                throw new RuntimeException('Raport sprzedaży musi zawierać co najmniej jedną pozycję.');
            }

            // Supersede BEFORE inserting. The unique index on
            // (profile, period, status) is what makes a duplicated month
            // impossible, and it fires on the new row if the old one is still
            // marked as being in force. Order here is load-bearing, not style.
            if ($existing !== null) {
                $existing->forceFill(['status' => SalesReportModel::STATUS_SUPERSEDED])->save();
            }

            $report = SalesReportModel::create([
                'tax_profile_id' => $profile->getKey(),
                'period' => $period->toString(),
                'register_id' => $registerId,
                'report_number' => $reportNumber,
                'gross_total' => $gross->jsonSerialize(),
                'net_total' => $net->jsonSerialize(),
                'vat_total' => $vat->jsonSerialize(),
                'status' => SalesReportModel::STATUS_RECORDED,
                'correction_reason' => $correctionReason,
            ]);

            foreach ($lines as $line) {
                $report->lines()->create([
                    'designation' => $line->designation,
                    'gross' => $line->gross->jsonSerialize(),
                    'net' => $line->net()->jsonSerialize(),
                    'vat' => $line->vat()->jsonSerialize(),
                    'lump_sum_rate' => $line->lumpSumRate,
                    'note' => $line->note,
                ]);
            }

            if ($existing !== null) {
                $existing->forceFill(['superseded_by_id' => $report->getKey()])->save();
            }

            $this->audit->record(
                $existing !== null ? AuditRecorder::SALES_CORRECTED : AuditRecorder::SALES_RECORDED,
                (int) $profile->getKey(),
                $report,
                $period->toString(),
                $existing !== null ? ['gross_total' => $existing->gross_total] : null,
                ['gross_total' => $gross->jsonSerialize(), 'reason' => $correctionReason],
            );

            return $report;
        });
    }

    /** Compute a month and store the result, without claiming it was filed. */
    public function settle(TaxProfileModel $profile, Period $period): MonthlyTaxReport
    {
        $ledger = $this->ledgers->forPeriod($profile, $period);
        $report = $this->engine->settle($profile->toDomain(), $ledger, $period);

        $settlement = SettlementModel::updateOrCreate(
            ['tax_profile_id' => $profile->getKey(), 'period' => $period->toString()],
            [
                'gross_sales' => $report->grossSales->jsonSerialize(),
                'revenue_for_income_tax' => $report->revenueForIncomeTax->jsonSerialize(),
                'zus_total' => $report->zus->total->jsonSerialize(),
                'zus_social' => $report->zus->socialTotal->jsonSerialize(),
                'zus_health' => $report->zus->health->jsonSerialize(),
                'vat_due' => $report->vat->amountToPay->jsonSerialize(),
                'pit_due' => $report->pit->advanceDue->jsonSerialize(),
                'total_due' => $report->totalDue->jsonSerialize(),
                'is_estimate' => $report->isEstimate,
                'rates_fit_for_filing' => $report->ratesFitForFiling,
                'stage' => SettlementStage::Calculated->value,
                'report' => json_decode(json_encode($report, JSON_THROW_ON_ERROR), true),
                'rate_sources' => $report->rateSources,
                'rate_provenance' => json_decode(json_encode($report->rateProvenance, JSON_THROW_ON_ERROR), true),
                'computed_at' => now(),
            ],
        );

        $this->audit->record(
            AuditRecorder::SETTLEMENT_COMPUTED,
            (int) $profile->getKey(),
            $settlement,
            $period->toString(),
            null,
            [
                'zus' => $report->zus->total->jsonSerialize(),
                'vat' => $report->vat->amountToPay->jsonSerialize(),
                'pit' => $report->pit->advanceDue->jsonSerialize(),
                'total' => $report->totalDue->jsonSerialize(),
                'is_estimate' => $report->isEstimate,
                'rates_fit_for_filing' => $report->ratesFitForFiling,
                'stage' => SettlementStage::Calculated->value,
            ],
        );

        return $report;
    }

    /**
     * PREPARATION — build and validate a document for a channel.
     *
     * Refuses on a settlement that is not fit for filing. Preparing a document
     * from an estimate, or from rates nobody has verified, produces a file that
     * looks exactly like a real one and that somebody will eventually send.
     */
    public function prepare(
        TaxProfileModel $profile,
        Period $period,
        DocumentPreparer $preparer,
    ): PreparedDocumentModel {
        $settlement = SettlementModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->first();

        if ($settlement === null) {
            throw new RuntimeException(sprintf(
                'Nie ma wyliczenia za %s. Najpierw wylicz miesiąc, potem przygotuj dokument.',
                $period->toString(),
            ));
        }

        if (! $settlement->fitForFiling()) {
            throw new RuntimeException(sprintf(
                "Nie można przygotować dokumentu za %s:\n  - %s\nDokument zbudowany z takich "
                ."danych wygląda identycznie jak prawdziwy i ktoś go w końcu wyśle.",
                $period->toString(),
                implode("\n  - ", $settlement->blockersToFiling()),
            ));
        }

        if (! $preparer->supports($profile->toDomain(), $period)) {
            throw new RuntimeException(sprintf(
                'Kanał %s nie obsługuje okresu %s dla tego podatnika.',
                $preparer->channel()->value,
                $period->toString(),
            ));
        }

        $document = $preparer->prepare($profile->toDomain(), $period, [
            'settlement' => $settlement->report,
            'rate_provenance' => $settlement->rate_provenance,
        ]);

        if (! $document->isValid()) {
            throw new RuntimeException(sprintf(
                "Dokument %s za %s nie przeszedł walidacji:\n  - %s",
                $document->schema->identifier(),
                $period->toString(),
                $document->validationErrors === []
                    ? 'nie udało się go zwalidować (brak schematu XSD) — niezwalidowany dokument nie jest dokumentem poprawnym'
                    : implode("\n  - ", $document->validationErrors),
            ));
        }

        return DB::transaction(function () use ($profile, $period, $settlement, $document): PreparedDocumentModel {
            $model = PreparedDocumentModel::create([
                'settlement_id' => $settlement->getKey(),
                'channel' => $document->channel->value,
                'period' => $period->toString(),
                'schema_structure' => $document->schema->structure,
                'schema_version' => $document->schema->version,
                'content_type' => $document->contentType,
                'content' => $document->content,
                'checksum' => $document->checksum(),
                'rule_versions' => $document->ruleVersions,
                'validated' => $document->validated,
                'validation_errors' => $document->validationErrors,
                'idempotency_key' => $document->idempotencyKey,
                'prepared_at' => now(),
            ]);

            $settlement->forceFill([
                'prepared_at' => now(),
                'stage' => SettlementStage::Prepared->value,
            ])->save();

            $this->audit->record(
                AuditRecorder::DOCUMENT_PREPARED,
                (int) $profile->getKey(),
                $model,
                $period->toString(),
                null,
                [
                    'channel' => $document->channel->value,
                    'schema' => $document->schema->identifier(),
                    'checksum' => $document->checksum(),
                ],
            );

            return $model;
        });
    }

    /**
     * FILING — submit a prepared document.
     *
     * Only an accepted result with a reference advances the stage. Anything
     * else is recorded as an attempt and the settlement stays where it was.
     */
    public function file(
        TaxProfileModel $profile,
        PreparedDocumentModel $document,
        DocumentSubmitter $submitter,
    ): PreparedDocumentModel {
        if ($document->isSubmitted()) {
            throw new RuntimeException(sprintf(
                'Dokument %s za %s został już złożony (referencja %s). Ponowna wysyłka '
                .'utworzyłaby drugie zgłoszenie tego samego okresu.',
                $document->channel,
                $document->period,
                $document->submission_reference,
            ));
        }

        if (! $submitter->isConfigured()) {
            throw new RuntimeException(sprintf(
                'Kanał %s nie jest skonfigurowany — nie można niczego wysłać.',
                $document->channel,
            ));
        }

        $prepared = new \Poland\Contracts\PreparedDocument(
            FilingChannel::from($document->channel),
            Period::parse($document->period),
            new \Poland\Contracts\SchemaVersion(
                $document->schema_structure,
                $document->schema_version,
                Period::parse($document->period),
                null,
            ),
            $document->content_type,
            $document->content,
            $document->rule_versions ?? [],
            [],
            (bool) $document->validated,
            $document->idempotency_key,
        );

        try {
            $result = $submitter->submit($prepared);
        } catch (\Throwable $e) {
            $this->audit->record(
                AuditRecorder::DOCUMENT_SUBMITTED,
                (int) $profile->getKey(),
                $document,
                $document->period,
                null,
                ['channel' => $document->channel],
                'error',
                $e->getMessage(),
            );

            throw $e;
        }

        if (! $result->accepted()) {
            $this->audit->record(
                AuditRecorder::DOCUMENT_SUBMITTED,
                (int) $profile->getKey(),
                $document,
                $document->period,
                null,
                ['channel' => $document->channel, 'status' => $result->status],
                'rejected',
                $result->error,
            );

            throw new RuntimeException(sprintf(
                'Zgłoszenie %s za %s nie zostało przyjęte (status: %s). Nic nie zostało '
                .'oznaczone jako złożone.',
                $document->channel,
                $document->period,
                $result->status,
            ));
        }

        return DB::transaction(function () use ($profile, $document, $result): PreparedDocumentModel {
            $document->forceFill([
                'submitted_at' => $result->at,
                'submission_reference' => $result->reference,
                'submission_response' => $result->jsonSerialize(),
            ])->save();

            $this->markFiled(
                $profile,
                Period::parse($document->period),
                $document->channel,
                (string) $result->reference,
            );

            return $document;
        });
    }

    /**
     * Record that a settlement really was submitted.
     *
     * Requires a channel and a reference from the receiving system, because a
     * submission nobody can point at is not a submission.
     */
    public function markFiled(
        TaxProfileModel $profile,
        Period $period,
        string $channel,
        string $reference,
    ): SettlementModel {
        if (trim($reference) === '') {
            throw new RuntimeException(
                'Nie można oznaczyć rozliczenia jako złożonego bez numeru referencyjnego '
                .'zwróconego przez system odbierający.',
            );
        }

        $settlement = SettlementModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->firstOrFail();

        $settlement->forceFill([
            'filed_at' => now(),
            'filing_channel' => $channel,
            'filing_reference' => $reference,
            'stage' => SettlementStage::Filed->value,
        ])->save();

        $this->audit->record(
            AuditRecorder::SETTLEMENT_FILED,
            (int) $profile->getKey(),
            $settlement,
            $period->toString(),
            null,
            ['channel' => $channel, 'reference' => $reference],
        );

        return $settlement;
    }
}
