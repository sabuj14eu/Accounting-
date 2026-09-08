<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;
use Poland\Ksef\Secret;

final class TokenInfo
{
    public function __construct(
        public readonly Secret $token,
        public readonly DateTimeImmutable $validUntil,
    ) {
    }

    public function isValidAt(DateTimeImmutable $now, int $marginSeconds = 60): bool
    {
        return $this->validUntil->getTimestamp() - $marginSeconds > $now->getTimestamp();
    }
}
