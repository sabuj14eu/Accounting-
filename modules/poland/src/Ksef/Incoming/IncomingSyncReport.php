<?php

declare(strict_types=1);

namespace Poland\Ksef\Incoming;

use DateTimeImmutable;

/** What one synchronisation run did, including what it could not do. */
final class IncomingSyncReport implements \JsonSerializable
{
    /**
     * @param list<string> $imported
     * @param list<string> $duplicates
     * @param array<string,string> $needsReview ksefNumber => reason
     */
    public function __construct(
        public readonly string $subjectType,
        public readonly ?DateTimeImmutable $windowFrom,
        public readonly ?DateTimeImmutable $windowTo,
        public readonly int $pagesPersisted,
        public readonly array $imported,
        public readonly array $duplicates,
        public readonly array $needsReview,
        public readonly bool $completed,
        public readonly ?string $stoppedBecause,
        public readonly SyncCursorState $cursorAfter,
    ) {
    }

    public function isClean(): bool
    {
        return $this->completed && $this->stoppedBecause === null;
    }

    public function summary(): string
    {
        $base = sprintf(
            '%s: pobrano %d nowych faktur, %d duplikatów pominięto, %d do przeglądu, %d stron zapisanych.',
            $this->subjectType,
            count($this->imported),
            count($this->duplicates),
            count($this->needsReview),
            $this->pagesPersisted,
        );
        if (! $this->completed) {
            return $base.' Synchronizacja PRZERWANA: '.($this->stoppedBecause ?? 'nieznany powód').'. Kursor pozostał na ostatniej bezpiecznej stronie — nic nie zostało pominięte.';
        }

        return $base;
    }

    public function jsonSerialize(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'window_from' => $this->windowFrom?->format(DATE_ATOM),
            'window_to' => $this->windowTo?->format(DATE_ATOM),
            'pages_persisted' => $this->pagesPersisted,
            'imported' => $this->imported,
            'duplicates' => $this->duplicates,
            'needs_review' => $this->needsReview,
            'completed' => $this->completed,
            'stopped_because' => $this->stoppedBecause,
            'cursor_after' => $this->cursorAfter->toArray(),
            'summary' => $this->summary(),
        ];
    }
}
