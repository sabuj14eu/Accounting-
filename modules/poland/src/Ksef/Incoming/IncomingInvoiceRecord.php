<?php

declare(strict_types=1);

namespace Poland\Ksef\Incoming;

use Poland\Ksef\KsefInvoiceMetadata;
use Poland\Ksef\Parsing\ParsedInvoice;

/**
 * One invoice as it will be persisted: KSeF's metadata, the XML if it could
 * be fetched, what was parsed, and whether a person must look at it.
 *
 * `$xml === null` is a stated fact ("KSeF listed it; the body could not be
 * fetched in this run"), never a silent gap: the record still lands, marked
 * for review, so the month is not short and the fetch can be retried.
 */
final class IncomingInvoiceRecord
{
    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

    public function __construct(
        public readonly KsefInvoiceMetadata $metadata,
        public readonly string $subjectType,
        public readonly string $direction,
        public readonly ?string $xml,
        public readonly ?ParsedInvoice $parsed,
        public readonly bool $needsReview,
        public readonly ?string $reviewReason,
    ) {
    }

    public function ksefNumber(): string
    {
        return $this->metadata->ksefNumber;
    }
}
