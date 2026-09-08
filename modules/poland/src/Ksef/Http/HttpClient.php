<?php

declare(strict_types=1);

namespace Poland\Ksef\Http;

/**
 * The narrowest HTTP contract the transport needs, so the transport's own
 * parsing and classification can be tested with scripted responses and the
 * real client can be swapped without touching it.
 */
interface HttpClient
{
    /**
     * @throws HttpFailure when no complete response was obtained
     */
    public function send(HttpRequest $request): HttpResponse;
}
