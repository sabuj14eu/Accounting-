<?php

declare(strict_types=1);

namespace Poland\Tests\Support;

use Poland\Ksef\Http\HttpResponse;
use Poland\Ksef\Http\HttpTransport;
use Poland\Ksef\Http\KsefTransportException;

/**
 * A scripted HTTP transport: responses are queued per (METHOD path-prefix)
 * and served in order; every request is recorded so a test can assert what
 * went over the wire — and, more importantly, what did not.
 */
final class RecordedTransport implements HttpTransport
{
    /** @var array<string, list<HttpResponse|\Throwable>> */
    private array $queue = [];

    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];

    public function on(string $method, string $pathPrefix, HttpResponse|\Throwable ...$responses): self
    {
        $key = strtoupper($method).' '.$pathPrefix;
        foreach ($responses as $r) {
            $this->queue[$key][] = $r;
        }

        return $this;
    }

    public static function json(int $status, array $data, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function raw(int $status, string $body, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, $body);
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach ($this->queue as $key => $list) {
            [$m, $prefix] = explode(' ', $key, 2);
            if ($m !== strtoupper($method) || ! str_starts_with($path, $prefix)) {
                continue;
            }
            if ($list === []) {
                continue;
            }
            $next = array_shift($this->queue[$key]);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        }

        throw KsefTransportException::network('RecordedTransport: brak nagranej odpowiedzi dla '.$method.' '.$path);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (array $r): string => $r['method'].' '.parse_url($r['url'], PHP_URL_PATH).(parse_url($r['url'], PHP_URL_QUERY) ? '?'.parse_url($r['url'], PHP_URL_QUERY) : ''), $this->requests);
    }
}
