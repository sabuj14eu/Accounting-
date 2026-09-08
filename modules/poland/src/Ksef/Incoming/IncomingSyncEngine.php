<?php

declare(strict_types=1);

namespace Poland\Ksef\Incoming;

use DateTimeImmutable;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Audit\KsefAuditSink;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\KsefInvoiceMetadata;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\InvoiceQuery;
use Poland\Ksef\Transport\KsefTransport;

/**
 * Incremental retrieval as the pinned guide prescribes
 * (`przyrostowe-pobieranie-faktur.md`, `hwm.md`, and the `/invoices/query/metadata`
 * description in the OpenAPI): PermanentStorage dates, ascending, the window
 * capped by the server's HWM, pages by offset, and on `isTruncated` a new
 * window from the last record's storage date.
 *
 * And the rule this system adds on top:
 *
 *   request page → validate → fetch bodies → persist ALL of the page + the
 *   advanced cursor in ONE transaction → next page
 *
 * A failure anywhere in a page leaves the cursor where the previous page left
 * it. The next run requests the same page again. Duplicates are caught by the
 * unique index; gaps are caught by nothing, which is why the order is what it is.
 *
 * A transport failure is thrown, never turned into "no invoices".
 */
final class IncomingSyncEngine
{
    /** @param \Closure(int):void|null $sleeper */
    public function __construct(
        private readonly KsefTransport $transport,
        private readonly FaInvoiceParser $parser,
        private readonly KsefAuditSink $audit,
        private readonly int $pageSize = 100,
        private readonly ?\Closure $sleeper = null,
        private readonly int $delayBetweenFetchesSeconds = 0,
    ) {
        if ($pageSize < 10 || $pageSize > 250) {
            throw new \InvalidArgumentException('pageSize must be 10..250 per the contract.');
        }
    }

    public function run(
        Secret $accessToken,
        SyncStore $store,
        string $taxpayerNip,
        string $subjectType,
        DateTimeImmutable $fallbackFrom,
        int $maxPages = 50,
    ): IncomingSyncReport {
        $environment = $this->transport->environment()->value;
        $cursor = $store->loadCursor($subjectType);

        if ($cursor->inProgress && $cursor->windowFrom !== null && $cursor->windowTo !== null) {
            $from = $cursor->windowFrom;
            $to = $cursor->windowTo;
            $offset = $cursor->pageOffset;
        } else {
            $from = $cursor->syncedThrough ?? $fallbackFrom;
            $to = null; // the server caps at its HWM; we learn it from the first page
            $offset = 0;
        }

        $imported = [];
        $duplicates = [];
        $needsReview = [];
        $pagesPersisted = 0;
        $completed = false;
        $stoppedBecause = null;

        try {
            for ($pageNo = 0; $pageNo < $maxPages; $pageNo++) {
                $query = InvoiceQuery::incremental($subjectType, $from, $to);
                $page = $this->transport->queryInvoiceMetadata($accessToken, $query, $offset, $this->pageSize, 'Asc');

                if ($to === null) {
                    if ($page->permanentStorageHwmDate === null) {
                        throw new KsefException(
                            KsefErrorCategory::CursorError,
                            'queryInvoiceMetadata',
                            'KSeF nie zwrócił permanentStorageHwmDate — bez znacznika kompletności nie można bezpiecznie ustalić końca okna. Nic nie zapisano.',
                            environment: $environment,
                        );
                    }
                    $to = $page->permanentStorageHwmDate;
                }

                $records = [];
                foreach ($page->invoices as $metadata) {
                    if ($store->hasInvoice($metadata->ksefNumber)) {
                        $duplicates[] = $metadata->ksefNumber;

                        continue;
                    }
                    $record = $this->fetch($accessToken, $metadata, $taxpayerNip, $subjectType);
                    $records[] = $record;
                    if ($record->needsReview) {
                        $needsReview[$record->ksefNumber()] = (string) $record->reviewReason;
                    }
                }

                $last = $page->last();
                if ($page->hasMore && $page->isTruncated && $last?->permanentStorageDate !== null) {
                    $next = $cursor->afterTruncation($last->permanentStorageDate, $to);
                } elseif ($page->hasMore) {
                    $next = $cursor->afterPage($from, $to, $offset + 1);
                } else {
                    $next = $cursor->completed($to);
                }

                $inserted = $store->persistPage($subjectType, $records, $next);
                $pagesPersisted++;
                foreach ($records as $record) {
                    $imported[] = $record->ksefNumber();
                }
                $this->audit->record(KsefAuditActions::INCOMING_PAGE_PERSISTED, [
                    'environment' => $environment,
                    'subject_type' => $subjectType,
                    'window_from' => $from->format(DATE_ATOM),
                    'window_to' => $to->format(DATE_ATOM),
                    'page_offset' => $offset,
                    'records' => count($records),
                    'inserted' => $inserted,
                    'duplicates_on_page' => $page->count() - count($records),
                    'cursor_after' => $next->toArray(),
                ]);

                $cursor = $next;
                if (! $page->hasMore) {
                    $completed = true;
                    break;
                }
                $from = $next->windowFrom ?? $from;
                $offset = $next->pageOffset;
            }
            if (! $completed) {
                $stoppedBecause = sprintf('osiągnięto limit %d stron w jednym przebiegu', $maxPages);
            }
        } catch (KsefException $e) {
            $stoppedBecause = $e->getMessage();
            $this->audit->record(KsefAuditActions::SYNC_FAILED, ['environment' => $environment, 'subject_type' => $subjectType, 'cursor' => $cursor->toArray()] + $e->toArray(), 'failed', $e->getMessage());
        } catch (\Throwable $e) {
            // The store refused a page (constraint, connection). Nothing from that
            // page is persisted; the cursor is where the previous page left it.
            $stoppedBecause = 'zapis strony nie powiódł się: '.$e->getMessage();
            $this->audit->record(KsefAuditActions::SYNC_FAILED, ['environment' => $environment, 'subject_type' => $subjectType, 'cursor' => $cursor->toArray(), 'error' => get_class($e)], 'failed', $stoppedBecause);
        }

        $report = new IncomingSyncReport(
            $subjectType,
            $from,
            $to,
            $pagesPersisted,
            $imported,
            $duplicates,
            $needsReview,
            $completed,
            $stoppedBecause,
            $cursor,
        );
        $this->audit->record(KsefAuditActions::INCOMING_SYNCED, ['environment' => $environment] + $report->jsonSerialize(), $report->isClean() ? 'ok' : 'partial', $stoppedBecause);

        return $report;
    }

    private function fetch(Secret $accessToken, KsefInvoiceMetadata $metadata, string $taxpayerNip, string $subjectType): IncomingInvoiceRecord
    {
        $direction = $subjectType === InvoiceQuery::SUBJECT_SELLER || $metadata->isOutgoingFor($taxpayerNip)
            ? IncomingInvoiceRecord::DIRECTION_OUTGOING
            : IncomingInvoiceRecord::DIRECTION_INCOMING;

        if ($this->delayBetweenFetchesSeconds > 0) {
            ($this->sleeper ?? static fn (int $s) => sleep($s))($this->delayBetweenFetchesSeconds);
        }

        try {
            $xml = $this->transport->invoiceXml($accessToken, $metadata->ksefNumber);
        } catch (KsefException $e) {
            if ($e->category->isRetryable()) {
                // A lost connection mid-page fails the page: nothing persisted.
                throw $e;
            }

            return new IncomingInvoiceRecord($metadata, $subjectType, $direction, null, null, true, 'Nie pobrano treści faktury: '.$e->getMessage());
        }

        try {
            $parsed = $this->parser->parse($xml, $metadata->ksefNumber, $metadata->retrievedAt);
        } catch (\RuntimeException $e) {
            return new IncomingInvoiceRecord($metadata, $subjectType, $direction, $xml, null, true, 'XML faktury nieczytelny: '.$e->getMessage());
        }

        $hashMismatch = false;
        $expectedHash = $metadata->raw['invoiceHash'] ?? null;
        if (is_string($expectedHash) && $expectedHash !== '' && ! hash_equals($expectedHash, base64_encode(hash('sha256', $xml, true)))) {
            $hashMismatch = true;
        }

        $reason = match (true) {
            $hashMismatch => 'Skrót SHA-256 pobranego XML nie zgadza się ze skrótem z metadanych KSeF.',
            $metadata->isCorrection() => 'Faktura korygująca — wymaga przypisania do faktury pierwotnej.',
            $parsed->totalsAgree() === false => 'Suma netto i VAT nie zgadza się z kwotą brutto na fakturze.',
            ! $parsed->isComplete() => 'Nie odczytano wszystkich wymaganych pól: '.implode(', ', $parsed->missing),
            default => null,
        };

        return new IncomingInvoiceRecord($metadata, $subjectType, $direction, $xml, $parsed, $reason !== null, $reason);
    }
}
