<?php

declare(strict_types=1);

namespace Poland\Ksef;

use DateTimeImmutable;

/**
 * The rule for advancing the synchronisation high-water mark.
 *
 *   request page → validate → persist all invoices → commit → advance cursor
 *   anything fails → roll back → the cursor does NOT advance
 *
 * Extracted from the ingest service so the rule itself can be tested without a
 * database. Advancing the mark after a partial run permanently skips whatever
 * was never reached, and nothing downstream can detect the gap — the months
 * simply come out short, once, forever.
 */
final class SyncCursor
{
    private function __construct(
        public readonly ?DateTimeImmutable $syncedThrough,
        public readonly ?string $pageCursor,
        public readonly bool $completed,
    ) {
    }

    public static function initial(): self
    {
        return new self(null, null, true);
    }

    public static function resume(?DateTimeImmutable $syncedThrough, ?string $pageCursor): self
    {
        return new self($syncedThrough, $pageCursor, $pageCursor === null);
    }

    /**
     * Decide the state to persist after a run.
     *
     * @param DateTimeImmutable $requestedThrough the end of the window that was asked for
     * @param string|null $pageCursor where the run stopped inside that window
     */
    public function after(
        DateTimeImmutable $requestedThrough,
        bool $completed,
        ?string $pageCursor = null,
    ): self {
        if (! $completed) {
            // Keep the OLD high-water mark. The next run re-requests the same
            // window; duplicates are caught by the unique index, gaps are not
            // caught by anything.
            return new self($this->syncedThrough, $pageCursor, false);
        }

        return new self($requestedThrough, null, true);
    }

    /** Where the next run must start so nothing is skipped. */
    public function nextWindowStart(DateTimeImmutable $fallback): DateTimeImmutable
    {
        return $this->syncedThrough ?? $fallback;
    }

    public function isResumable(): bool
    {
        return ! $this->completed;
    }

    /** @return array{synced_through: ?string, cursor: ?string, completed: bool} */
    public function toArray(): array
    {
        return [
            'synced_through' => $this->syncedThrough?->format('Y-m-d H:i:s'),
            'cursor' => $this->pageCursor,
            'completed' => $this->completed,
        ];
    }
}
