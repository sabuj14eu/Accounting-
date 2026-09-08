<?php

declare(strict_types=1);

namespace Poland\Ksef\Auth;

use DateTimeImmutable;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\TokenInfo;

/**
 * A successful authentication: the tokens, when they die, and what KSeF says
 * the token may do. Immutable; refreshing produces a new instance.
 */
final class AuthenticatedContext
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly string $referenceNumber,
        public readonly string $contextNip,
        public readonly TokenInfo $accessToken,
        public readonly TokenInfo $refreshToken,
        public readonly array $permissions,
        public readonly ?string $authenticationMethod,
        public readonly DateTimeImmutable $authenticatedAt,
        public readonly ?DateTimeImmutable $lastRefreshedAt = null,
    ) {
    }

    public function bearer(): Secret
    {
        return $this->accessToken->token;
    }

    public function isAccessTokenValid(DateTimeImmutable $now, int $marginSeconds = 60): bool
    {
        return $this->accessToken->isValidAt($now, $marginSeconds);
    }

    public function canRefresh(DateTimeImmutable $now): bool
    {
        return $this->refreshToken->isValidAt($now, 60);
    }

    public function withAccessToken(TokenInfo $accessToken, DateTimeImmutable $refreshedAt): self
    {
        return new self(
            $this->referenceNumber,
            $this->contextNip,
            $accessToken,
            $this->refreshToken,
            $this->permissions,
            $this->authenticationMethod,
            $this->authenticatedAt,
            $refreshedAt,
        );
    }

    /** @return array<string,mixed> safe to store or show */
    public function summary(): array
    {
        return [
            'reference_number' => $this->referenceNumber,
            'context_nip' => $this->contextNip,
            'access_token_valid_until' => $this->accessToken->validUntil->format(DATE_ATOM),
            'refresh_token_valid_until' => $this->refreshToken->validUntil->format(DATE_ATOM),
            'permissions' => $this->permissions,
            'authentication_method' => $this->authenticationMethod,
            'authenticated_at' => $this->authenticatedAt->format(DATE_ATOM),
            'last_refreshed_at' => $this->lastRefreshedAt?->format(DATE_ATOM),
        ];
    }
}
