<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

/**
 * The production HTTP transport: PHP's curl extension, TLS verification on,
 * no redirects followed, explicit timeouts.
 *
 * Redirects are refused on purpose. A token must never be re-sent to a host
 * the operator did not configure, and a 3xx from the API would be a change in
 * the API — which is something to notice, not to follow.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $timeoutSeconds = 60,
        private readonly ?string $caBundlePath = null,
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        if (! function_exists('curl_init')) {
            throw KsefTransportException::config('Rozszerzenie PHP curl nie jest zainstalowane.');
        }
        if (! str_starts_with($url, 'https://')) {
            throw KsefTransportException::config('Adres KSeF musi używać https:// — odmowa wysłania tokenu jawnym kanałem.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw KsefTransportException::network('nie udało się zainicjować curl');
        }

        $rawHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // https only, whichever spelling this curl build supports.
            (defined('CURLOPT_PROTOCOLS_STR') ? CURLOPT_PROTOCOLS_STR : CURLOPT_PROTOCOLS)
                => defined('CURLOPT_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$rawHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $rawHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        } elseif (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            // A body-less POST (auth/challenge, token/redeem) still needs
            // Content-Length: 0, or the server may answer 411.
            $options[CURLOPT_POSTFIELDS] = '';
        }
        if ($this->caBundlePath !== null && $this->caBundlePath !== '') {
            $options[CURLOPT_CAINFO] = $this->caBundlePath;
        }
        curl_setopt_array($handle, $options);

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);

            // curl error strings name hosts and TLS details, never credentials.
            throw KsefTransportException::network(sprintf('%s (curl %d)', $error, $errno));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, $rawHeaders, (string) $responseBody);
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[] = $name.': '.$value;
        }

        return $out;
    }
}
