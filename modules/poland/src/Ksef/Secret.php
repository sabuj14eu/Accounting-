<?php

declare(strict_types=1);

namespace Poland\Ksef;

/**
 * A credential value that refuses to be printed.
 *
 * KSeF tokens, access tokens, refresh tokens, AES keys: every one of them is a
 * bearer secret, and every one of them is one careless `print_r` away from a
 * log file. So they travel wrapped, and the wrapper redacts itself in every
 * form PHP can render an object in. The one way to the real value is
 * {@see reveal()}, which exists so a `grep reveal(` finds every call site.
 */
final class Secret implements \JsonSerializable
{
    private function __construct(private readonly string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('A secret cannot be empty.');
        }
    }

    public static function of(string $value): self
    {
        return new self($value);
    }

    /** The only accessor. One name, greppable. */
    public function reveal(): string
    {
        return $this->value;
    }

    /** A stable, non-reversible identifier for "which secret", safe to show. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->value), 0, 12);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return '[REDACTED secret '.$this->fingerprint().']';
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]', 'fingerprint' => $this->fingerprint()];
    }

    public function jsonSerialize(): string
    {
        return '[REDACTED]';
    }

    /** @return array<string,string> */
    public function __serialize(): array
    {
        // A secret must not survive a trip through a queue payload or a cache in
        // the clear. Anything that needs the value must hold the object in
        // memory or store it through an encrypted column.
        return ['value' => '[REDACTED]'];
    }

    /** @param array<string,string> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A serialised Secret carries no value and cannot be restored.');
    }
}
