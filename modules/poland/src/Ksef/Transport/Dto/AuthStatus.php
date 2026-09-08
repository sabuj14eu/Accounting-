<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;

/**
 * GET /auth/{referenceNumber}. Codes per the pinned OpenAPI:
 * 100 in progress · 200 success · 415 no permissions · 425 revoked ·
 * 450 bad token · 460 certificate error · 470 other failure.
 */
final class AuthStatus
{
    /** @param list<string> $details */
    public function __construct(
        public readonly int $code,
        public readonly string $description,
        public readonly array $details,
        public readonly ?bool $isTokenRedeemed,
        public readonly ?DateTimeImmutable $refreshTokenValidUntil,
        public readonly ?string $authenticationMethod,
    ) {
    }

    public function isInProgress(): bool
    {
        return $this->code === 100;
    }

    public function isSuccess(): bool
    {
        return $this->code === 200;
    }
}
