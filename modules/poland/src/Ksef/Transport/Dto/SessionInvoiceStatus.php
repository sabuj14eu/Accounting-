<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;

/**
 * GET /sessions/{ref}/invoices/{invoiceRef}. Status codes per the pinned
 * OpenAPI: 100 accepted for processing · 150 processing · 200 success ·
 * 405 cancelled by session error · 410 wrong permission scope · 415 attachment
 * not allowed · 430 file verification error · 435 decryption error ·
 * 440 duplicate (extensions carry originalSessionReferenceNumber and
 * originalKsefNumber) · 450 semantic validation error · 500 unknown ·
 * 550 cancelled by the system.
 */
final class SessionInvoiceStatus
{
    /**
     * @param list<string> $details
     * @param array<string,string|null> $extensions
     */
    public function __construct(
        public readonly int $ordinalNumber,
        public readonly ?string $invoiceNumber,
        public readonly ?string $ksefNumber,
        public readonly string $referenceNumber,
        public readonly string $invoiceHash,
        public readonly ?DateTimeImmutable $acquisitionDate,
        public readonly DateTimeImmutable $invoicingDate,
        public readonly ?DateTimeImmutable $permanentStorageDate,
        public readonly ?string $upoDownloadUrl,
        public readonly ?DateTimeImmutable $upoDownloadUrlExpirationDate,
        public readonly ?string $invoicingMode,
        public readonly int $statusCode,
        public readonly string $statusDescription,
        public readonly array $details,
        public readonly array $extensions,
    ) {
    }
}
