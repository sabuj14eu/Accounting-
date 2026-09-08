<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;

final class AuthChallenge
{
    public function __construct(
        public readonly string $challenge,
        public readonly DateTimeImmutable $timestamp,
        public readonly int $timestampMs,
    ) {
    }
}
