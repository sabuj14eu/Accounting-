<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;

final class OpenedSession
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly DateTimeImmutable $validUntil,
    ) {
    }
}
