<?php

declare(strict_types=1);

namespace Poland\Ksef\Contracts;

use DateTimeImmutable;

/**
 * An open KSeF session.
 *
 * The token is deliberately NOT exposed through any accessor that a logger, a
 * serialiser or a debug page could reach: __toString, jsonSerialize and
 * __debugInfo all redact it. Getting the real value requires calling
 * {@see self::reveal()}, which exists at exactly one call site — the HTTP
 * client — and is easy to grep for.
 */
final class KsefSession
{
    public function __construct(
        private readonly string $token,
        public readonly string $reference,
        public readonly DateTimeImmutable $openedAt,
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    /** The only way to obtain the token. One call site, greppable. */
    public function reveal(): string
    {
        return $this->token;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?? new DateTimeImmutable()) >= $this->expiresAt;
    }

    public function __toString(): string
    {
        return 'KsefSession('.$this->reference.', token=[REDACTED])';
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'reference' => $this->reference,
            'token' => '[REDACTED]',
            'opened_at' => $this->openedAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
        ];
    }
}
