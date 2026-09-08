<?php

declare(strict_types=1);

namespace Poland\Ksef\Auth;

use DateTimeImmutable;
use Poland\Ksef\Secret;

/**
 * Reads the payload of a KSeF access token WITHOUT verifying it.
 *
 * That is deliberate and safe for the one thing it is used for: showing the
 * operator which permissions the token carries and when it expires. No
 * authorisation decision is ever made from these claims — KSeF makes those,
 * on every request, with the signature it issued.
 */
final class JwtClaims
{
    /** @param array<string,mixed> $claims */
    private function __construct(public readonly array $claims)
    {
    }

    public static function decode(Secret $jwt): self
    {
        $parts = explode('.', $jwt->reveal());
        if (count($parts) !== 3) {
            return new self([]);
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/').str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        if ($payload === false) {
            return new self([]);
        }
        $decoded = json_decode($payload, true);

        return new self(is_array($decoded) ? $decoded : []);
    }

    /**
     * Permission names found in the token, whatever claim they travel under.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        $found = [];
        foreach ($this->claims as $key => $value) {
            if (! is_string($key) || stripos($key, 'perm') === false && stripos($key, 'role') === false) {
                continue;
            }
            $nested = stripos($key, 'perm') !== false;
            foreach ((array) $value as $item) {
                if (is_string($item) && $item !== '') {
                    $found[] = $item;
                } elseif ($nested && is_array($item)) {
                    foreach ($item as $inner) {
                        if (is_string($inner) && $inner !== '') {
                            $found[] = $inner;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        $exp = $this->claims['exp'] ?? null;

        return is_numeric($exp) ? (new DateTimeImmutable('@'.(int) $exp)) : null;
    }

    public function isEmpty(): bool
    {
        return $this->claims === [];
    }
}
