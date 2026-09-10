<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

/**
 * The one seam between the KSeF client and the network.
 *
 * Kept deliberately tiny so the client can be tested with recorded responses
 * and so the production implementation ({@see CurlTransport}) is short enough
 * to review line by line. Implementations must send exactly the headers they
 * are given and must never log a request that carries an Authorization
 * header or an encrypted token.
 */
interface HttpTransport
{
    /**
     * @param array<string,string> $headers
     *
     * @throws KsefTransportException on a network-level failure (DNS, TLS,
     *         timeout, connection reset). An HTTP error status is NOT an
     *         exception here — it is returned, and the client decides.
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
