<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;

/** GET /sessions/{referenceNumber}. */
final class SessionStatus
{
    /**
     * @param list<string> $details
     * @param list<array{referenceNumber: string, downloadUrl: string}> $upoPages
     */
    public function __construct(
        public readonly int $code,
        public readonly string $description,
        public readonly array $details,
        public readonly DateTimeImmutable $dateCreated,
        public readonly DateTimeImmutable $dateUpdated,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly ?int $invoiceCount,
        public readonly ?int $successfulInvoiceCount,
        public readonly ?int $failedInvoiceCount,
        public readonly array $upoPages,
    ) {
    }
}
