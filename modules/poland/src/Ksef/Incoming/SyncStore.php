<?php

declare(strict_types=1);

namespace Poland\Ksef\Incoming;

/**
 * Persistence for the incremental sync, shaped so the one rule that matters
 * is a single method: {@see persistPage()} stores every record of a page AND
 * the advanced cursor in one atomic unit, or nothing.
 *
 * The Laravel binding wraps it in a database transaction; the in-memory
 * binding used in tests emulates the same all-or-nothing behaviour.
 */
interface SyncStore
{
    public function loadCursor(string $subjectType): SyncCursorState;

    /** Whether this KSeF number is already stored — the unique index is the real guard. */
    public function hasInvoice(string $ksefNumber): bool;

    /**
     * @param list<IncomingInvoiceRecord> $records
     * @return int how many records were newly inserted (duplicates skipped by the index count 0)
     * @throws \Throwable on any failure; NOTHING from this call may remain persisted
     */
    public function persistPage(string $subjectType, array $records, SyncCursorState $cursor): int;
}
