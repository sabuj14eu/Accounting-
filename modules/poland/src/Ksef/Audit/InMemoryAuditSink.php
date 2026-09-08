<?php

declare(strict_types=1);

namespace Poland\Ksef\Audit;

/** Collects audit events for tests and for the standalone CLI. */
final class InMemoryAuditSink implements KsefAuditSink
{
    /** @var list<array{action: string, context: array<string,mixed>, result: string, error: ?string}> */
    public array $events = [];

    public function record(string $action, array $context = [], string $result = 'ok', ?string $error = null): void
    {
        $this->events[] = ['action' => $action, 'context' => $context, 'result' => $result, 'error' => $error];
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_map(static fn (array $e): string => $e['action'], $this->events);
    }

    public function has(string $action): bool
    {
        return in_array($action, $this->actions(), true);
    }

    /** Everything recorded, flattened to one string, for "no secret appears" assertions. */
    public function dump(): string
    {
        return json_encode($this->events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
