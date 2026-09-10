<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

use RuntimeException;

/**
 * Anything that stops the client from getting an answer it can trust.
 *
 * Every message is written so it can be shown to the operator and so it never
 * contains a token, a challenge, an encrypted blob or an Authorization header.
 * `kind` lets the ingest layer and the go-live check tell the classes apart
 * without parsing Polish prose.
 */
final class KsefTransportException extends RuntimeException
{
    public const NETWORK = 'network';

    public const MALFORMED = 'malformed';

    public const REJECTED = 'rejected';         // 4xx other than auth/limit

    public const UNAUTHORISED = 'unauthorised'; // 401/403, revoked or expired

    public const RATE_LIMITED = 'rate_limited'; // 429 after retries were spent

    public const SERVER = 'server';             // 5xx

    public const AUTH_FAILED = 'auth_failed';   // KSeF said the authentication failed

    public const CONFIG = 'config';             // refused before any request was sent

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $traceId = null,
        /** @var list<int> */
        public readonly array $apiErrorCodes = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function network(string $detail, ?\Throwable $previous = null): self
    {
        return new self(self::NETWORK, 'Błąd sieci przy połączeniu z KSeF: '.$detail, null, null, [], $previous);
    }

    public static function malformed(string $detail): self
    {
        return new self(self::MALFORMED, 'Niepoprawna odpowiedź KSeF: '.$detail);
    }

    public static function config(string $detail): self
    {
        return new self(self::CONFIG, $detail);
    }

    public static function authFailed(string $detail, array $codes = []): self
    {
        return new self(self::AUTH_FAILED, 'Uwierzytelnienie w KSeF nie powiodło się: '.$detail, null, null, $codes);
    }

    /**
     * Build from a non-2xx response, reading the Problem Details body when
     * there is one. The body is quoted only through its `title`/`detail`/error
     * codes — never verbatim, in case the server echoes request material.
     */
    public static function fromResponse(HttpResponse $response, string $operation): self
    {
        $title = null;
        $detail = null;
        $trace = null;
        $codes = [];

        $decoded = json_decode($response->body, true);
        if (is_array($decoded)) {
            $title = isset($decoded['title']) ? (string) $decoded['title'] : null;
            $detail = isset($decoded['detail']) ? (string) $decoded['detail'] : null;
            $trace = isset($decoded['traceId']) ? (string) $decoded['traceId'] : null;
            foreach ((array) ($decoded['errors'] ?? []) as $error) {
                if (is_array($error) && isset($error['code'])) {
                    $codes[] = (int) $error['code'];
                }
            }
            // Deprecated (non-problem-details) shape, still documented.
            if (isset($decoded['exception']['exceptionDetailList']) && is_array($decoded['exception']['exceptionDetailList'])) {
                foreach ($decoded['exception']['exceptionDetailList'] as $error) {
                    if (is_array($error) && isset($error['exceptionCode'])) {
                        $codes[] = (int) $error['exceptionCode'];
                    }
                }
            }
        }

        $kind = match (true) {
            $response->status === 401, $response->status === 403 => self::UNAUTHORISED,
            $response->status === 429 => self::RATE_LIMITED,
            $response->status >= 500 => self::SERVER,
            default => self::REJECTED,
        };

        $summary = trim(implode(' ', array_filter([$title, $detail])));
        $message = sprintf(
            'KSeF odrzucił operację "%s": HTTP %d%s%s%s.',
            $operation,
            $response->status,
            $summary !== '' ? ' — '.$summary : '',
            $codes !== [] ? ' (kody: '.implode(', ', array_unique($codes)).')' : '',
            $trace !== null ? ' [traceId '.$trace.']' : '',
        );

        return new self($kind, $message, $response->status, $trace, array_values(array_unique($codes)));
    }

    public function isCredentialProblem(): bool
    {
        return in_array($this->kind, [self::UNAUTHORISED, self::AUTH_FAILED], true);
    }
}
