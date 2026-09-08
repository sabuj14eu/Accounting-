<?php

declare(strict_types=1);

namespace Poland\Tests\Support;

use Poland\Ksef\Incoming\IncomingInvoiceRecord;
use Poland\Ksef\Incoming\SyncCursorState;
use Poland\Ksef\Incoming\SyncStore;

/**
 * Emulates the database's all-or-nothing page write: records and cursor
 * land together, or — when `$failOnPersist` fires — nothing lands and the
 * cursor keeps its previous value.
 */
final class InMemorySyncStore implements SyncStore
{
    /** @var array<string, SyncCursorState> */
    public array $cursors = [];

    /** @var array<string, IncomingInvoiceRecord> keyed by KSeF number, insertion order */
    public array $records = [];

    /** @var list<array{subject: string, count: int, cursor: array<string,mixed>}> */
    public array $commits = [];

    /** @var list<int> page indexes (1-based) whose persist must fail */
    public array $failOnPersist = [];

    private int $persistCalls = 0;

    public function loadCursor(string $subjectType): SyncCursorState
    {
        return $this->cursors[$subjectType] ?? SyncCursorState::initial();
    }

    public function hasInvoice(string $ksefNumber): bool
    {
        return isset($this->records[$ksefNumber]);
    }

    public function persistPage(string $subjectType, array $records, SyncCursorState $cursor): int
    {
        $this->persistCalls++;
        if (in_array($this->persistCalls, $this->failOnPersist, true)) {
            throw new \RuntimeException('simulated database failure on persist #'.$this->persistCalls);
        }
        // Stage, then commit: a unique-key violation would leave nothing behind.
        $staged = [];
        foreach ($records as $record) {
            if (isset($this->records[$record->ksefNumber()]) || isset($staged[$record->ksefNumber()])) {
                continue;
            }
            $staged[$record->ksefNumber()] = $record;
        }
        $this->records += $staged;
        $this->cursors[$subjectType] = $cursor;
        $this->commits[] = ['subject' => $subjectType, 'count' => count($staged), 'cursor' => $cursor->toArray()];

        return count($staged);
    }
}
