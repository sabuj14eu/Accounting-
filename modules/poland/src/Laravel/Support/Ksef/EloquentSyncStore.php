<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use Illuminate\Support\Facades\DB;
use Poland\Domain\Period;
use Poland\Ksef\Incoming\IncomingInvoiceRecord;
use Poland\Ksef\Incoming\SyncCursorState;
use Poland\Ksef\Incoming\SyncStore;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\KsefStatusEventModel;
use Poland\Laravel\Models\KsefSyncCursorModel;

/**
 * The database binding of the one rule: a page's invoices and the advanced
 * cursor are written in ONE transaction. If any insert fails, the transaction
 * rolls back, the cursor stays, and the next run asks for the same page.
 *
 * The unique index on (tax_profile_id, ksef_number) is the real duplicate
 * guard; `hasInvoice()` only saves a fetch.
 */
final class EloquentSyncStore implements SyncStore
{
    /** @var list<string> KSeF numbers inserted by this store, for the caller's report */
    public array $inserted = [];

    public function __construct(
        private readonly int $taxProfileId,
        private readonly string $environment,
        private readonly ?int $syncRunId = null,
    ) {
    }

    public function loadCursor(string $subjectType): SyncCursorState
    {
        $row = KsefSyncCursorModel::query()
            ->where('tax_profile_id', $this->taxProfileId)
            ->where('environment', $this->environment)
            ->where('subject_type', $subjectType)
            ->first();

        return $row === null ? SyncCursorState::initial() : $row->toState();
    }

    public function hasInvoice(string $ksefNumber): bool
    {
        return KsefDocumentModel::query()
            ->where('tax_profile_id', $this->taxProfileId)
            ->where('ksef_number', $ksefNumber)
            ->exists();
    }

    public function persistPage(string $subjectType, array $records, SyncCursorState $cursor): int
    {
        return DB::transaction(function () use ($subjectType, $records, $cursor): int {
            $inserted = 0;
            foreach ($records as $record) {
                if ($this->insert($record)) {
                    $inserted++;
                    $this->inserted[] = $record->ksefNumber();
                }
            }

            KsefSyncCursorModel::query()->updateOrCreate(
                ['tax_profile_id' => $this->taxProfileId, 'environment' => $this->environment, 'subject_type' => $subjectType],
                KsefSyncCursorModel::attributesFor($cursor) + ['last_run_at' => now(), 'last_run_completed' => ! $cursor->inProgress],
            );

            return $inserted;
        });
    }

    private function insert(IncomingInvoiceRecord $record): bool
    {
        $m = $record->metadata;
        $p = $record->parsed;

        // KSeF's metadata is the authority's record; the XML is what the issuer wrote.
        $net = $m->net ?? $p?->metadata->net;
        $vat = $m->vat ?? $p?->metadata->vat;
        $gross = $m->gross ?? $p?->metadata->gross;
        $invoiceDate = $m->invoiceDate ?? $p?->metadata->invoiceDate;
        $missing = array_values(array_unique(array_merge($p?->missing ?? [], $m->missingFields())));
        $raw = $m->raw;

        // A retry of the same page after a partial failure must not blow up on
        // the unique index: the second attempt sees the row and skips it.
        if ($this->hasInvoice($m->ksefNumber)) {
            return false;
        }

        $document = KsefDocumentModel::create([
            'tax_profile_id' => $this->taxProfileId,
            'ksef_number' => $m->ksefNumber,
            'invoice_number' => $m->invoiceNumber ?? $p?->metadata->invoiceNumber,
            'invoice_type' => $m->invoiceType ?? $p?->metadata->invoiceType,
            'direction' => $record->direction,
            'subject_type' => $record->subjectType,
            'sync_run_id' => $this->syncRunId,
            'invoice_date' => $invoiceDate?->format('Y-m-d'),
            'sale_date' => $p?->saleDate?->format('Y-m-d'),
            'permanent_storage_date' => $m->permanentStorageDate,
            'acquisition_date' => isset($raw['acquisitionDate']) ? new \DateTimeImmutable((string) $raw['acquisitionDate']) : null,
            'invoicing_date' => isset($raw['invoicingDate']) ? new \DateTimeImmutable((string) $raw['invoicingDate']) : null,
            'retrieved_at' => $m->retrievedAt,
            'seller_nip' => $m->sellerNip ?? $p?->metadata->sellerNip,
            'seller_name' => $m->sellerName ?? $p?->metadata->sellerName,
            'buyer_nip' => $m->buyerNip ?? $p?->metadata->buyerNip,
            'buyer_name' => $m->buyerName ?? $p?->metadata->buyerName,
            'net' => $net?->jsonSerialize(),
            'vat' => $vat?->jsonSerialize(),
            'gross' => $gross?->jsonSerialize(),
            'currency' => $m->currency ?? $p?->metadata->currency,
            'ksef_status' => $m->status,
            'original_xml' => $record->xml,
            'xml_checksum' => $record->xml === null ? null : hash('sha256', $record->xml),
            'invoice_hash_base64' => isset($raw['invoiceHash']) ? (string) $raw['invoiceHash'] : null,
            'form_system_code' => isset($raw['formCode']['systemCode']) ? (string) $raw['formCode']['systemCode'] : null,
            'form_schema_version' => isset($raw['formCode']['schemaVersion']) ? (string) $raw['formCode']['schemaVersion'] : null,
            'xml_missing_reason' => $record->xml === null ? $record->reviewReason : null,
            'metadata' => json_decode(json_encode($m, JSON_THROW_ON_ERROR), true),
            'parse_result' => $p === null ? ['parsed' => false] : json_decode(json_encode($p, JSON_THROW_ON_ERROR), true),
            'missing_fields' => $missing,
            'period' => $invoiceDate !== null
                ? Period::of((int) $invoiceDate->format('Y'), (int) $invoiceDate->format('n'))->toString()
                : null,
            'processing_status' => $record->xml === null ? 'xml_missing' : ($record->needsReview ? 'needs_review' : 'imported'),
            'needs_review' => $record->needsReview,
            'review_reason' => $record->reviewReason,
        ]);

        KsefStatusEventModel::create([
            'ksef_document_id' => $document->getKey(),
            'from_state' => null,
            'to_state' => strtoupper((string) $document->processing_status),
            'details' => ['ksef_number' => $m->ksefNumber, 'direction' => $record->direction, 'subject_type' => $record->subjectType],
            'source' => 'api',
            'reference' => $m->ksefNumber,
            'occurred_at' => now(),
        ]);

        return true;
    }
}
