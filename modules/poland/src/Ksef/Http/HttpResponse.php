<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

final class HttpResponse
{
    /** @param array<string,string> $headers lower-cased names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly float $elapsedSeconds = 0.0,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type') ?? ''), 'json');
    }
}
