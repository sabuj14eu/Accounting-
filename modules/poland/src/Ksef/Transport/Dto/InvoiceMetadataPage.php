<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;
use Poland\Ksef\KsefInvoiceMetadata;

/** One page of POST /invoices/query/metadata. */
final class InvoiceMetadataPage
{
    /** @param list<KsefInvoiceMetadata> $invoices */
    public function __construct(
        public readonly array $invoices,
        public readonly bool $hasMore,
        public readonly bool $isTruncated,
        public readonly ?DateTimeImmutable $permanentStorageHwmDate,
        public readonly int $pageOffset,
        public readonly int $pageSize,
    ) {
    }

    public function count(): int
    {
        return count($this->invoices);
    }

    public function last(): ?KsefInvoiceMetadata
    {
        return $this->invoices === [] ? null : $this->invoices[count($this->invoices) - 1];
    }
}
