<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

/**
 * One HTTP response, as the transport saw it.
 *
 * Headers are lower-cased on construction so `Retry-After` and `retry-after`
 * are the same header. The body is kept verbatim; decoding is the caller's
 * decision because a KSeF invoice comes back as XML and everything else as
 * JSON.
 */
final class HttpResponse
{
    /** @var array<string,string> */
    public readonly array $headers;

    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $lower;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The JSON body as an array.
     *
     * @return array<string,mixed>
     *
     * @throws KsefTransportException when the body is not a JSON object — a
     *         malformed response is reported, never read as an empty one.
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        if (! is_array($decoded)) {
            throw KsefTransportException::malformed(sprintf(
                'odpowiedź HTTP %d nie jest poprawnym dokumentem JSON (%s)',
                $this->status,
                json_last_error_msg(),
            ));
        }

        return $decoded;
    }

    /** Seconds to wait when the server sent 429 with Retry-After, else null. */
    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('retry-after');
        if ($value === null || $value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $at = strtotime($value);

        return $at === false ? null : max(0, $at - time());
    }
}
