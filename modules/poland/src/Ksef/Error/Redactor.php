<?php

declare(strict_types=1);

namespace Poland\Ksef\Error;

use Poland\Ksef\Secret;

/**
 * Removes credential material from text before it reaches a message, a log,
 * an audit row or a screen.
 *
 * Two layers: patterns for things that look like tokens (a JWT, a bearer
 * header), and the exact values of the secrets a request actually used, which
 * the transport hands over so an echoed value is caught even when it looks
 * like nothing in particular.
 */
final class Redactor
{
    /** @var list<string> */
    private array $values = [];

    public static function none(): self
    {
        return new self();
    }

    /** @param list<Secret|string> $secrets */
    public function withSecrets(array $secrets): self
    {
        $clone = clone $this;
        foreach ($secrets as $secret) {
            $value = $secret instanceof Secret ? $secret->reveal() : $secret;
            // A real credential is never this short; scrubbing a 1–7 character
            // value would mangle ordinary words in every message.
            if (strlen($value) >= 8) {
                $clone->values[] = $value;
            }
        }

        return $clone;
    }

    public function scrub(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        foreach ($this->values as $value) {
            $text = str_replace($value, '[REDACTED]', $text);
        }

        // A JWT: three base64url segments, the first starting with "eyJ".
        $text = (string) preg_replace('/eyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}/', '[REDACTED-JWT]', $text);
        // A bearer header, however it got there.
        $text = (string) preg_replace('/Bearer\s+[A-Za-z0-9._\-+\/=]{8,}/i', 'Bearer [REDACTED]', $text);
        // The environment variable names that carry secrets, when a message
        // quotes an .env line.
        $text = (string) preg_replace('/(KSEF_TOKEN|APP_KEY|DB_PASSWORD)\s*=\s*\S+/', '$1=[REDACTED]', $text);

        return $text;
    }

    /** @param list<string> $lines @return list<string> */
    public function scrubAll(array $lines): array
    {
        return array_values(array_map(fn (string $line): string => $this->scrub($line), $lines));
    }
}
