<?php

declare(strict_types=1);

namespace Poland\Ksef\Contracts;

use Poland\Ksef\KsefInvoiceMetadata;

/** One page of invoice metadata, with the cursor to continue from. */
final class KsefInvoicePage
{
    /** @param list<KsefInvoiceMetadata> $invoices */
    public function __construct(
        public readonly array $invoices,
        public readonly ?string $nextCursor = null,
        public readonly ?int $totalAvailable = null,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    public function count(): int
    {
        return count($this->invoices);
    }
}
