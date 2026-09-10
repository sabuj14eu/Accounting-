<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\SalesChannel;
use Poland\Domain\SalesLine;
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
     * Record a month's takings from the fiscal cash register (the shop channel).
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
        $lines = [];
        foreach ($grossByDesignation as $designation => $amount) {
            $lines[] = new SalesLine(
                (string) $designation,
                $amount instanceof Money ? $amount : Money::parse($amount),
                null,
                null,
                SalesChannel::SHOP_REGISTER,
            );
        }

        return $this->recordChannelLines($profile, $period, $lines, $correctionReason, null, null, $registerId, $reportNumber);
    }

    /**
     * Replace ONE channel's lines of a month's sales report, keeping every
     * other channel's lines as they are.
     *
     * The month stays one report (the unique index still forbids two Augusts);
     * the change is a correction that supersedes the previous report and needs
     * a reason whenever the same channel was already recorded. Recording Glovo
     * after the shop figure (or the reverse) is not a correction of anything
     * and needs none.
     *
     * @param list<SalesLine> $lines all on the same channel
     */
    public function recordChannelLines(
        TaxProfileModel $profile,
        Period $period,
        array $lines,
        ?string $correctionReason = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $registerId = null,
        ?string $reportNumber = null,
    ): SalesReportModel {
        if ($lines === []) {
            throw new RuntimeException('Raport sprzedaży musi zawierać co najmniej jedną pozycję.');
        }

        $channels = array_unique(array_map(static fn (SalesLine $l): string => $l->channel, $lines));
        if (count($channels) !== 1) {
            throw new RuntimeException('Jeden zapis dotyczy jednego kanału sprzedaży.');
        }
        $channel = $channels[0];

        return DB::transaction(function () use (
            $profile, $period, $lines, $channel, $correctionReason, $sourceType, $sourceId, $registerId, $reportNumber
        ): SalesReportModel {
            $existing = SalesReportModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('period', $period->toString())
                ->inForce()
                ->with('lines')
                ->first();

            $carried = [];
            $sameChannelBefore = false;
            if ($existing !== null) {
                foreach ($existing->lines as $row) {
                    $rowChannel = (string) ($row->channel ?? SalesChannel::SHOP_REGISTER);
                    if ($rowChannel === $channel) {
                        $sameChannelBefore = true;

                        continue;
                    }
                    $carried[] = [
                        'line' => new SalesLine(
                            (string) $row->designation,
                            Money::parse((string) $row->gross),
                            $row->lump_sum_rate !== null ? (float) $row->lump_sum_rate : null,
                            $row->note,
                            $rowChannel,
                        ),
                        'source_type' => $row->source_type,
                        'source_id' => $row->source_id,
                    ];
                }
            }

            if ($sameChannelBefore && ($correctionReason === null || trim($correctionReason) === '')) {
                throw new RuntimeException(sprintf(
                    'Sprzedaż (%s) za %s została już zapisana (%s). Zmiana wymaga podania przyczyny '
                    .'korekty — poprzedni raport zostanie zachowany, nie nadpisany.',
                    SalesChannel::label($channel),
                    $period->toString(),
                    Money::parse((string) $existing->gross_total)->format(),
                ));
            }

            $all = array_merge(
                $carried,
                array_map(static fn (SalesLine $l): array => ['line' => $l, 'source_type' => $sourceType, 'source_id' => $sourceId], $lines),
            );

            $gross = Money::zero();
            $net = Money::zero();
            $vat = Money::zero();
            foreach ($all as $entry) {
                $gross = $gross->plus($entry['line']->gross);
                $net = $net->plus($entry['line']->net());
                $vat = $vat->plus($entry['line']->vat());
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
                'register_id' => $registerId ?? $existing?->register_id,
                'report_number' => $reportNumber ?? $existing?->report_number,
                'gross_total' => $gross->jsonSerialize(),
                'net_total' => $net->jsonSerialize(),
                'vat_total' => $vat->jsonSerialize(),
                'status' => SalesReportModel::STATUS_RECORDED,
                'correction_reason' => $sameChannelBefore ? $correctionReason : ($existing !== null ? 'Dopisano kanał: '.SalesChannel::label($channel) : null),
            ]);

            foreach ($all as $entry) {
                /** @var SalesLine $line */
                $line = $entry['line'];
                $report->lines()->create([
                    'designation' => $line->designation,
                    'channel' => $line->channel,
                    'gross' => $line->gross->jsonSerialize(),
                    'net' => $line->net()->jsonSerialize(),
                    'vat' => $line->vat()->jsonSerialize(),
                    'lump_sum_rate' => $line->lumpSumRate,
                    'note' => $line->note,
                    'source_type' => $entry['source_type'],
                    'source_id' => $entry['source_id'],
                ]);
            }

            if ($existing !== null) {
                $existing->forceFill(['superseded_by_id' => $report->getKey()])->save();
            }

            $this->audit->record(
                $sameChannelBefore ? AuditRecorder::SALES_CORRECTED : AuditRecorder::SALES_RECORDED,
                (int) $profile->getKey(),
                $report,
                $period->toString(),
                $existing !== null ? ['gross_total' => $existing->gross_total] : null,
                [
                    'gross_total' => $gross->jsonSerialize(),
                    'channel' => $channel,
                    'channel_gross' => Money::sum(array_map(static fn (SalesLine $l): Money => $l->gross, $lines))->jsonSerialize(),
                    'reason' => $correctionReason,
                ],
            );

            return $report;
        });
    }

    /**
     * Mark the manual monthly purchase total as superseded by posted invoices.
     *
     * The two sources are never summed; this is how the owner resolves the
     * conflict in favour of the postings. The row stays, with the note.
     */
    public function supersedePurchaseSummary(TaxProfileModel $profile, Period $period, string $actor): void
    {
        $summary = \Poland\Laravel\Models\PurchaseSummaryModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->first();

        if ($summary === null) {
            return;
        }

        $old = ['costs_net' => $summary->deductible_costs_net, 'input_vat' => $summary->deductible_input_vat, 'note' => $summary->note];
        $summary->forceFill([
            'note' => trim('[ZASTĄPIONE przez zaksięgowane faktury — '.$actor.', '.now()->format('Y-m-d').'] '.(string) $summary->note),
            'superseded_at' => now(),
        ])->save();

        $this->audit->record(
            AuditRecorder::PURCHASE_SUMMARY_SUPERSEDED,
            (int) $profile->getKey(),
            $summary,
            $period->toString(),
            $old,
            ['superseded_by' => 'postings', 'by' => $actor],
        );
    }

    /** Record the month's deductible costs and input VAT. */
    public function recordPurchases(
        TaxProfileModel $profile,
        Period $period,
        Money $costsNet,
        Money $inputVat,
        int $documentCount = 0,
        ?string $note = null,
    ): \Poland\Laravel\Models\PurchaseSummaryModel {
        $summary = \Poland\Laravel\Models\PurchaseSummaryModel::updateOrCreate(
            ['tax_profile_id' => $profile->getKey(), 'period' => $period->toString()],
            [
                'deductible_costs_net' => $costsNet->jsonSerialize(),
                'deductible_input_vat' => $inputVat->jsonSerialize(),
                'document_count' => $documentCount,
                'note' => $note,
            ],
        );

        $this->audit->record(
            AuditRecorder::PURCHASES_RECORDED,
            (int) $profile->getKey(),
            $summary,
            $period->toString(),
            null,
            [
                'costs_net' => $costsNet->jsonSerialize(),
                'input_vat' => $inputVat->jsonSerialize(),
                'documents' => $documentCount,
            ],
        );

        return $summary;
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
