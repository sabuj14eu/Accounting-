<?php

declare(strict_types=1);

namespace Poland\Tests\Support;

use Poland\Ksef\Http\HttpClient;
use Poland\Ksef\Http\HttpFailure;
use Poland\Ksef\Http\HttpRequest;
use Poland\Ksef\Http\HttpResponse;

/** Answers requests from a queue, so the real transport's parsing and classification can be tested exactly. */
final class ScriptedHttpClient implements HttpClient
{
    /** @var list<HttpResponse|HttpFailure|\Closure> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    public function enqueue(HttpResponse|HttpFailure|\Closure $item): self
    {
        $this->queue[] = $item;

        return $this;
    }

    public function json(int $status, mixed $body, array $headers = []): self
    {
        return $this->enqueue(new HttpResponse($status, ['content-type' => 'application/json'] + $headers, is_string($body) ? $body : (string) json_encode($body)));
    }

    public function raw(int $status, string $body, string $contentType = 'application/xml'): self
    {
        return $this->enqueue(new HttpResponse($status, ['content-type' => $contentType], $body));
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \LogicException('ScriptedHttpClient: no response queued for '.$request->method.' '.$request->url);
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Closure) {
            $next = $next($request);
        }
        if ($next instanceof HttpFailure) {
            throw $next;
        }

        return $next;
    }

    public function last(): HttpRequest
    {
        return $this->requests[count($this->requests) - 1];
    }
}
