<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use DateTimeImmutable;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Incoming\IncomingSyncEngine;
use Poland\Ksef\Incoming\IncomingSyncReport;
use Poland\Ksef\KsefSyncResult;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Transport\Dto\InvoiceQuery;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\KsefSyncRunModel;
use Poland\Laravel\Models\ReportVersionModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\Ksef\EloquentSyncStore;
use Poland\Laravel\Support\Ksef\KsefErrorRecorder;
use Poland\Laravel\Support\Ksef\KsefSettingsService;
use Poland\Laravel\Support\Ksef\KsefTokenService;
use Poland\Laravel\Support\Ksef\KsefTransportFactory;
use Poland\Laravel\Support\Ksef\LaravelKsefAuditSink;
use RuntimeException;

/**
 * Downloads invoices from KSeF and files them — the Laravel face of
 * {@see IncomingSyncEngine}.
 *
 * Four rules the pipeline exists to hold:
 *  - never import the same invoice twice (enforced by a unique index);
 *  - never overwrite an imported invoice (the XML is immutable);
 *  - never lose position — a page is persisted with its cursor or not at all;
 *  - never silently change a report that has already been generated. A new
 *    invoice for a settled month marks that month REQUIRES REVIEW and stops.
 *
 * The public `sync(profile, from, to)` signature is kept for the month close;
 * with the HWM-based engine `from` is only the fallback start of the very
 * first window, and `to` is the server's completeness mark, never a guess.
 */
final class KsefIngestService
{
    public function __construct(
        private readonly KsefTransportFactory $transports,
        private readonly KsefSettingsService $settings,
        private readonly KsefTokenService $tokens,
        private readonly FaInvoiceParser $parser,
        private readonly LaravelKsefAuditSink $audit,
        private readonly KsefErrorRecorder $errors,
    ) {
    }

    public function isAvailable(TaxProfileModel $profile): bool
    {
        return $this->unavailableReason($profile) === null;
    }

    /** Why synchronisation cannot run, in words a screen can show. */
    public function unavailableReason(TaxProfileModel $profile): ?string
    {
        $gate = $this->transports->gate();
        if (! $gate->isEnabled()) {
            return $gate->status()['detail'];
        }
        $credential = $this->settings->current($profile);
        if ($credential === null) {
            return 'Nie skonfigurowano dostępu do KSeF dla tego podatnika.';
        }
        if (! $credential->isConfigured()) {
            return 'Konfiguracja KSeF jest niekompletna: '.implode(', ', $credential->missingConfiguration()).'.';
        }

        return $credential->unusableReason();
    }

    /**
     * Compatibility entry point used by the month close.
     */
    public function sync(
        TaxProfileModel $profile,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $maxInvoices = 500,
    ): KsefSyncResult {
        $reports = $this->syncIncremental($profile, [InvoiceQuery::SUBJECT_BUYER], 'month-close', $from);
        $report = $reports[0];
        $failures = $report->needsReview;
        if (! $report->completed && $report->stoppedBecause !== null) {
            $failures['__run__'] = $report->stoppedBecause;
        }

        return new KsefSyncResult(
            from: $report->windowFrom ?? $from,
            to: $report->windowTo ?? $to,
            importedNumbers: $report->imported,
            duplicateNumbers: $report->duplicates,
            failures: $failures,
            affectedPeriods: $this->periodsOf($profile, $report->imported),
            completed: $report->completed,
            stoppedBecause: $report->stoppedBecause,
            resumeFrom: $report->completed ? null : ($report->windowFrom ?? $from),
        );
    }

    /**
     * Incremental retrieval for each subject type, each with its own cursor.
     *
     * @param list<string>|null $subjectTypes
     * @return list<IncomingSyncReport>
     */
    public function syncIncremental(TaxProfileModel $profile, ?array $subjectTypes = null, string $triggeredBy = 'manual', ?DateTimeImmutable $fallbackFrom = null): array
    {
        $reason = $this->unavailableReason($profile);
        if ($reason !== null) {
            throw new RuntimeException($reason.' Nic nie pobrano — to NIE oznacza braku faktur.');
        }
        /** @var KsefCredentialModel $credential */
        $credential = $this->settings->current($profile);
        $credential->scopeEnum()->assertAllowed();

        $subjectTypes ??= (array) config('poland.ksef.sync.subject_types', [InvoiceQuery::SUBJECT_BUYER, InvoiceQuery::SUBJECT_SELLER]);
        $fallbackFrom ??= now()->subDays((int) config('poland.ksef.sync.lookback_days', 45))->startOfDay()->toDateTimeImmutable();
        $environment = $this->transports->environment()->value;
        $audit = $this->audit->forProfile((int) $profile->getKey());

        $reports = [];
        foreach ($subjectTypes as $subjectType) {
            $run = KsefSyncRunModel::create([
                'tax_profile_id' => $profile->getKey(),
                'environment' => $environment,
                'subject_type' => $subjectType,
                'started_at' => now(),
                'status' => 'RUNNING',
                'triggered_by' => $triggeredBy,
            ]);

            $store = new EloquentSyncStore((int) $profile->getKey(), $environment, (int) $run->getKey());
            try {
                $context = $this->tokens->context($credential);
                $engine = new IncomingSyncEngine(
                    $this->transports->transport(),
                    $this->parser,
                    $audit,
                    (int) config('poland.ksef.sync.page_size', 100),
                    null,
                    (int) config('poland.ksef.sync.fetch_delay_seconds', 1),
                );
                $report = $engine->run($context->bearer(), $store, (string) $credential->nip, $subjectType, $fallbackFrom, (int) config('poland.ksef.sync.max_pages_per_run', 50));
            } catch (KsefException $e) {
                $error = $this->errors->record($e, (int) $profile->getKey(), null, (int) $run->getKey());
                $run->forceFill(['status' => 'FAILED', 'finished_at' => now(), 'stopped_because' => $e->getMessage(), 'error_id' => $error->getKey()])->save();
                $credential->forceFill(['last_sync_at' => now(), 'last_sync_ok' => false, 'last_error' => $e->getMessage()])->save();
                $audit->record(KsefAuditActions::SYNC_FAILED, ['environment' => $environment, 'subject_type' => $subjectType, 'run_id' => $run->getKey()] + $e->toArray(), 'failed', $e->getMessage());

                throw new RuntimeException('Synchronizacja z KSeF niedostępna: '.$e->getMessage().' To NIE jest informacja o braku faktur.', 0, $e);
            }

            $run->forceFill([
                'status' => $report->completed ? 'COMPLETED' : ($report->pagesPersisted > 0 ? 'PARTIAL' : 'FAILED'),
                'finished_at' => now(),
                'window_from' => $report->windowFrom,
                'window_to' => $report->windowTo,
                'pages_persisted' => $report->pagesPersisted,
                'imported_count' => count($report->imported),
                'duplicate_count' => count($report->duplicates),
                'review_count' => count($report->needsReview),
                'report' => $report->jsonSerialize(),
                'stopped_because' => $report->stoppedBecause,
            ])->save();
            $credential->forceFill(['last_sync_at' => now(), 'last_sync_ok' => $report->completed, 'last_error' => $report->completed ? null : $report->stoppedBecause])->save();

            $this->flagAffectedReports($profile, $this->periodsOf($profile, $report->imported));
            $reports[] = $report;
        }

        return $reports;
    }

    /**
     * @param list<string> $ksefNumbers
     * @return list<string>
     */
    private function periodsOf(TaxProfileModel $profile, array $ksefNumbers): array
    {
        if ($ksefNumbers === []) {
            return [];
        }

        return KsefDocumentModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->whereIn('ksef_number', $ksefNumbers)
            ->whereNotNull('period')
            ->distinct()
            ->pluck('period')
            ->values()
            ->all();
    }

    /**
     * A new invoice for a month whose report already exists does NOT rewrite it.
     *
     * @param list<string> $periods
     */
    private function flagAffectedReports(TaxProfileModel $profile, array $periods): void
    {
        foreach ($periods as $period) {
            $latest = ReportVersionModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('period', $period)
                ->orderByDesc('version')
                ->first();

            if ($latest === null) {
                continue;
            }

            $this->audit->forProfile((int) $profile->getKey(), $period)->record(
                AuditRecorder::REPORT_REQUIRES_REVIEW,
                ['reason' => 'Nowe faktury z KSeF po wygenerowaniu raportu.', 'report_version' => $latest->version],
            );
        }
    }
}
