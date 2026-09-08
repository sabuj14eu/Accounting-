<?php

declare(strict_types=1);

namespace Poland\Ksef\Outgoing;

use DateTimeImmutable;

/** What KSeF acknowledged when the invoice was handed over. Not an acceptance. */
final class SendReceipt
{
    public function __construct(
        public readonly string $sessionReference,
        public readonly string $invoiceReference,
        public readonly string $invoiceHashBase64,
        public readonly int $invoiceSize,
        public readonly string $encryptedHashBase64,
        public readonly int $encryptedSize,
        public readonly DateTimeImmutable $sentAt,
        /** True when the reference was found by asking KSeF after an uncertain send, not from the send itself. */
        public readonly bool $recovered = false,
    ) {
    }
}
