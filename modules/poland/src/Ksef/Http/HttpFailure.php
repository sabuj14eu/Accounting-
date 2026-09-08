<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

/**
 * The connection failed before a full HTTP response arrived.
 *
 * `$requestWasSent` is the distinction that matters: a DNS or connect failure
 * proves the server never saw the request; a read timeout proves nothing.
 */
final class HttpFailure extends \RuntimeException
{
    public const CONNECT = 'connect';

    public const TIMEOUT = 'timeout';

    public const TOO_LARGE = 'too_large';

    public const TLS = 'tls';

    public const OTHER = 'other';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly bool $requestWasSent,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
