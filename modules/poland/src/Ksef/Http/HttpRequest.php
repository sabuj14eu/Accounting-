<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

/** One outbound HTTP request, with the headers a log must never see kept separate. */
final class HttpRequest
{
    /**
     * @param array<string,string> $headers
     * @param array<string,string> $secretHeaders headers whose values are credentials
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly array $secretHeaders = [],
        public readonly int $connectTimeoutSeconds = 10,
        public readonly int $requestTimeoutSeconds = 30,
        public readonly int $maxResponseBytes = 5_000_000,
    ) {
    }

    /** @return array<string,string> */
    public function allHeaders(): array
    {
        return $this->headers + $this->secretHeaders;
    }

    /** Headers safe to record. */
    public function loggableHeaders(): array
    {
        $out = $this->headers;
        foreach ($this->secretHeaders as $name => $_) {
            $out[$name] = '[REDACTED]';
        }

        return $out;
    }
}
