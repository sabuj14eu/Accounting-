<?php

declare(strict_types=1);

namespace Poland\Ksef\Outgoing;

use DateTimeImmutable;
use Poland\Ksef\Crypto\EncryptionMaterial;

/** An open interactive session and the symmetric key it was opened with. */
final class SessionHandle
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly DateTimeImmutable $validUntil,
        public readonly EncryptionMaterial $material,
    ) {
    }
}
