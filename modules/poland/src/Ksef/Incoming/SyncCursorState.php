<?php

declare(strict_types=1);

namespace Poland\Ksef\Incoming;

use DateTimeImmutable;

/**
 * Where an incremental synchronisation stands, precisely enough to resume
 * from the exact page that failed.
 *
 *   syncedThrough  the server's completeness mark (HWM) up to which every
 *                  invoice is persisted — the only value the next window
 *                  may start from;
 *   windowFrom/To  the window currently being paged, `windowTo` fixed to
 *                  the HWM the server reported on the first page so later
 *                  pages see the same ordered set;
 *   pageOffset     the next page to request inside that window.
 *
 * It advances only inside the same transaction that persisted the page.
 */
final class SyncCursorState
{
    public function __construct(
        public readonly ?DateTimeImmutable $syncedThrough,
        public readonly ?DateTimeImmutable $windowFrom,
        public readonly ?DateTimeImmutable $windowTo,
        public readonly int $pageOffset,
        public readonly bool $inProgress,
    ) {
        if ($pageOffset < 0) {
            throw new \InvalidArgumentException('pageOffset >= 0');
        }
    }

    public static function initial(): self
    {
        return new self(null, null, null, 0, false);
    }

    /** After a page was persisted and there are more pages in the same window. */
    public function afterPage(DateTimeImmutable $windowFrom, DateTimeImmutable $windowTo, int $nextPageOffset): self
    {
        return new self($this->syncedThrough, $windowFrom, $windowTo, $nextPageOffset, true);
    }

    /** After a truncated result: continue from the last record's storage date, page 0. */
    public function afterTruncation(DateTimeImmutable $newWindowFrom, DateTimeImmutable $windowTo): self
    {
        return new self($this->syncedThrough, $newWindowFrom, $windowTo, 0, true);
    }

    /** After the last page of the window: the HWM becomes the mark, nothing is in progress. */
    public function completed(DateTimeImmutable $through): self
    {
        return new self($through, null, null, 0, false);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'synced_through' => $this->syncedThrough?->format(DATE_ATOM),
            'window_from' => $this->windowFrom?->format(DATE_ATOM),
            'window_to' => $this->windowTo?->format(DATE_ATOM),
            'page_offset' => $this->pageOffset,
            'in_progress' => $this->inProgress,
        ];
    }
}
