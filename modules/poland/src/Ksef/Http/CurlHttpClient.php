<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

/**
 * cURL with the settings a government API demands: explicit connect and
 * total timeouts, TLS verification that cannot be switched off, no redirects
 * (a redirect to another host would carry the bearer token with it), and a
 * hard cap on the response size enforced while the body streams in.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly string $userAgent = 'SignalMesh-Accounts-KSeF/1.0',
    ) {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('Rozszerzenie curl nie jest dostępne — transport KSeF nie może działać.');
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new HttpFailure(HttpFailure::OTHER, 'curl_init failed', false);
        }

        $responseHeaders = [];
        $body = '';
        $limit = $request->maxResponseBytes;
        $tooLarge = false;

        $headerLines = [];
        foreach ($request->allHeaders() as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }
        $headerLines[] = 'User-Agent: '.$this->userAgent;
        $headerLines[] = 'Expect:';

        curl_setopt_array($handle, [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $request->requestTimeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $limit, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $tooLarge = true;

                    return 0; // aborts the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        if ($this->caBundle !== null) {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
        }

        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $started = microtime(true);
        $ok = curl_exec($handle);
        $elapsed = microtime(true) - $started;
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($tooLarge) {
            throw new HttpFailure(HttpFailure::TOO_LARGE, sprintf('Odpowiedź przekroczyła limit %d bajtów.', $limit), true);
        }

        if ($ok === false || $errno !== 0) {
            $kind = match ($errno) {
                CURLE_OPERATION_TIMEDOUT => HttpFailure::TIMEOUT,
                CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_PROXY => HttpFailure::CONNECT,
                CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER, CURLE_PEER_FAILED_VERIFICATION => HttpFailure::TLS,
                default => HttpFailure::OTHER,
            };
            // Once any byte of the response has arrived, or the transfer timed
            // out after connecting, the request was on the wire.
            $sent = $kind === HttpFailure::TIMEOUT ? ($status > 0 || $body !== '' || $elapsed >= $request->connectTimeoutSeconds) : false;

            throw new HttpFailure($kind, sprintf('curl(%d): %s', $errno, $error), $sent);
        }

        return new HttpResponse($status, $responseHeaders, $body, $elapsed);
    }
}
