<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;
use Poland\Ksef\Secret;

/** Response of POST /auth/ksef-token: the operation reference and its temporary token. */
final class AuthInitiated
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly Secret $authenticationToken,
        public readonly ?DateTimeImmutable $validUntil,
    ) {
    }
}
