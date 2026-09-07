<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Poland\Domain\Period;
use Poland\Ksef\Contracts\KsefClient;
use Poland\Ksef\KsefInvoiceMetadata;
use Poland\Ksef\KsefSyncResult;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\ReportVersionModel;
use Poland\Laravel\Models\TaxProfileModel;
use RuntimeException;
use Throwable;

/**
 * Downloads incoming invoices from KSeF and files them.
 *
 * Four rules the pipeline exists to hold:
 *  - never import the same invoice twice (enforced by a unique index);
 *  - never overwrite an imported invoice (the XML is immutable);
 *  - never lose position — an interrupted run resumes from where it stopped;
 *  - never silently change a report that has already been generated. A new
 *    invoice for a settled month marks that month REQUIRES REVIEW and stops.
 */
final class KsefIngestService
{
    public function __construct(
        private readonly KsefClient $client,
        private readonly FaInvoiceParser $parser,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function isAvailable(TaxProfileModel $profile): bool
    {
        return $this->client->isConfigured() && $this->credentials($profile)?->isUsable() === true;
    }

    /** Why synchronisation cannot run, in words a screen can show. */
    public function unavailableReason(TaxProfileModel $profile): ?string
    {
        if (! $this->client->isConfigured()) {
            return 'Klient KSeF nie jest skonfigurowany (brak adresu środowiska lub implementacji transportu).';
        }

        $credentials = $this->credentials($profile);
        if ($credentials === null) {
            return 'Nie skonfigurowano dostępu do KSeF dla tego podatnika.';
        }

        return $credentials->unusableReason();
    }

    /**
     * Pull everything received between two dates.
     *
     * The range is closed and explicit rather than "since last time", so a rerun
     * of the same window is safe and produces duplicates that are detected, not
     * gaps that are not.
     */
    public function sync(
        TaxProfileModel $profile,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $maxInvoices = 500,
    ): KsefSyncResult {
        $reason = $this->unavailableReason($profile);
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $this->client->scope()->assertAllowed();

        $session = $this->client->openSession();

        $imported = [];
        $duplicates = [];
        $failures = [];
        $periods = [];
        $cursor = $this->state($profile)['cursor'] ?? null;
        $completed = true;
        $stoppedBecause = null;

        try {
            do {
                $page = $this->client->queryInvoices($session, $from, $to, $cursor);

                foreach ($page->invoices as $metadata) {
                    if (count($imported) >= $maxInvoices) {
                        $completed = false;
                        $stoppedBecause = sprintf('osiągnięto limit %d faktur w jednym przebiegu', $maxInvoices);
                        break 2;
                    }

                    if ($this->alreadyHave($profile, $metadata->ksefNumber)) {
                        $duplicates[] = $metadata->ksefNumber;

                        continue;
                    }

                    try {
                        $document = $this->import($profile, $session, $metadata);
                        $imported[] = $metadata->ksefNumber;

                        if ($document->period !== null) {
                            $periods[$document->period] = true;
                        }
                    } catch (Throwable $e) {
                        // One bad invoice must not abandon the rest, and must not
                        // vanish either.
                        $failures[$metadata->ksefNumber] = $e->getMessage();
                    }
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null);
        } catch (Throwable $e) {
            $completed = false;
            $stoppedBecause = $e->getMessage();
        } finally {
            try {
                $this->client->closeSession($session);
            } catch (Throwable) {
                // Closing is best-effort; the import already happened.
            }
        }

        $affected = array_keys($periods);
        $this->flagAffectedReports($profile, $affected);
        $this->rememberState($profile, $to, $completed ? null : $cursor, $completed);

        $result = new KsefSyncResult(
            from: $from,
            to: $to,
            importedNumbers: $imported,
            duplicateNumbers: $duplicates,
            failures: $failures,
            affectedPeriods: $affected,
            completed: $completed,
            stoppedBecause: $stoppedBecause,
            resumeFrom: $completed ? null : $from,
        );

        $this->audit->record(
            AuditRecorder::KSEF_SYNCED,
            (int) $profile->getKey(),
            null,
            null,
            null,
            $result->jsonSerialize(),
            $result->isClean() ? 'ok' : 'partial',
            $stoppedBecause,
            'ksef',
        );

        return $result;
    }

    private function import(
        TaxProfileModel $profile,
        \Poland\Ksef\Contracts\KsefSession $session,
        KsefInvoiceMetadata $metadata,
    ): KsefDocumentModel {
        $xml = $this->client->fetchInvoiceXml($session, $metadata->ksefNumber);
        $parsed = $this->parser->parse($xml, $metadata->ksefNumber, $metadata->retrievedAt);

        // KSeF's own metadata wins where both have a value: it is the
        // authority's record, and the XML is what the issuer wrote.
        $net = $metadata->net ?? $parsed->metadata->net;
        $vat = $metadata->vat ?? $parsed->metadata->vat;
        $gross = $metadata->gross ?? $parsed->metadata->gross;
        $invoiceDate = $metadata->invoiceDate ?? $parsed->metadata->invoiceDate;

        $taxpayerNip = (string) ($profile->nip ?? '');
        $direction = $metadata->isOutgoingFor($taxpayerNip)
            ? KsefDocumentModel::DIRECTION_OUTGOING
            : KsefDocumentModel::DIRECTION_INCOMING;

        $missing = array_values(array_unique(array_merge(
            $parsed->missing,
            $metadata->missingFields(),
        )));

        $needsReview = ! $parsed->isComplete()
            || $parsed->totalsAgree() === false
            || $metadata->isCorrection();

        $reviewReason = match (true) {
            $metadata->isCorrection() => 'Faktura korygująca — wymaga przypisania do faktury pierwotnej.',
            $parsed->totalsAgree() === false => 'Suma netto i VAT nie zgadza się z kwotą brutto na fakturze.',
            ! $parsed->isComplete() => 'Nie odczytano wszystkich wymaganych pól: '.implode(', ', $missing),
            default => null,
        };

        return DB::transaction(function () use (
            $profile, $metadata, $xml, $parsed, $net, $vat, $gross,
            $invoiceDate, $direction, $missing, $needsReview, $reviewReason
        ): KsefDocumentModel {
            $document = KsefDocumentModel::create([
                'tax_profile_id' => $profile->getKey(),
                'ksef_number' => $metadata->ksefNumber,
                'invoice_number' => $metadata->invoiceNumber ?? $parsed->metadata->invoiceNumber,
                'invoice_type' => $metadata->invoiceType ?? $parsed->metadata->invoiceType,
                'direction' => $direction,
                'invoice_date' => $invoiceDate?->format('Y-m-d'),
                'sale_date' => $parsed->saleDate?->format('Y-m-d'),
                'permanent_storage_date' => $metadata->permanentStorageDate,
                'retrieved_at' => $metadata->retrievedAt,
                'seller_nip' => $metadata->sellerNip ?? $parsed->metadata->sellerNip,
                'seller_name' => $metadata->sellerName ?? $parsed->metadata->sellerName,
                'buyer_nip' => $metadata->buyerNip ?? $parsed->metadata->buyerNip,
                'buyer_name' => $metadata->buyerName ?? $parsed->metadata->buyerName,
                'net' => $net?->jsonSerialize(),
                'vat' => $vat?->jsonSerialize(),
                'gross' => $gross?->jsonSerialize(),
                'currency' => $metadata->currency ?? $parsed->metadata->currency,
                'ksef_status' => $metadata->status,
                'original_xml' => $xml,
                'xml_checksum' => hash('sha256', $xml),
                'metadata' => json_decode(json_encode($metadata, JSON_THROW_ON_ERROR), true),
                'parse_result' => json_decode(json_encode($parsed, JSON_THROW_ON_ERROR), true),
                'missing_fields' => $missing,
                'period' => $invoiceDate !== null
                    ? Period::of((int) $invoiceDate->format('Y'), (int) $invoiceDate->format('n'))->toString()
                    : null,
                'processing_status' => $needsReview ? 'needs_review' : 'imported',
                'needs_review' => $needsReview,
                'review_reason' => $reviewReason,
            ]);

            $this->audit->record(
                AuditRecorder::KSEF_INVOICE_IMPORTED,
                (int) $profile->getKey(),
                $document,
                $document->period,
                null,
                [
                    'ksef_number' => $metadata->ksefNumber,
                    'direction' => $direction,
                    'gross' => $gross?->jsonSerialize(),
                    'needs_review' => $needsReview,
                ],
                'ok',
                null,
                'ksef',
            );

            return $document;
        });
    }

    private function alreadyHave(TaxProfileModel $profile, string $ksefNumber): bool
    {
        return KsefDocumentModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('ksef_number', $ksefNumber)
            ->exists();
    }

    /**
     * A new invoice for a month whose report already exists does NOT rewrite it.
     *
     * The report stays exactly as generated; the month is marked as needing
     * review so a person decides whether to regenerate. Silently changing a
     * report somebody has already acted on is the failure this prevents.
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

            $this->audit->record(
                AuditRecorder::REPORT_REQUIRES_REVIEW,
                (int) $profile->getKey(),
                $latest,
                $period,
                null,
                [
                    'reason' => 'Nowe faktury z KSeF po wygenerowaniu raportu.',
                    'report_version' => $latest->version,
                ],
                'ok',
                null,
                'ksef',
            );
        }
    }

    /** @return array{synced_through: ?string, cursor: ?string} */
    private function state(TaxProfileModel $profile): array
    {
        $row = DB::table('pl_ksef_sync_state')
            ->where('tax_profile_id', $profile->getKey())
            ->first();

        return [
            'synced_through' => $row->synced_through ?? null,
            'cursor' => $row->cursor ?? null,
        ];
    }

    private function rememberState(
        TaxProfileModel $profile,
        DateTimeImmutable $through,
        ?string $cursor,
        bool $completed,
    ): void {
        $environment = $this->credentials($profile)?->environment ?? 'test';
        $state = $this->state($profile);

        // The advance rule lives in SyncCursor so it can be tested without a
        // database: the mark moves only on a complete run, and a partial run
        // keeps the OLD mark rather than clearing it.
        $next = \Poland\Ksef\SyncCursor::resume(
            $state['synced_through'] !== null ? new DateTimeImmutable($state['synced_through']) : null,
            $state['cursor'],
        )->after($through, $completed, $cursor);

        DB::table('pl_ksef_sync_state')->updateOrInsert(
            ['tax_profile_id' => $profile->getKey(), 'environment' => $environment],
            $next->toArray() + [
                'last_run_at' => now(),
                'last_run_completed' => $completed,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function credentials(TaxProfileModel $profile): ?KsefCredentialModel
    {
        return KsefCredentialModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->orderByDesc('enabled')
            ->first();
    }
}
