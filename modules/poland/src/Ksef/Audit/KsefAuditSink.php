<?php

declare(strict_types=1);

namespace Poland\Ksef\Audit;

/**
 * Where the framework-free KSeF code reports what it did.
 *
 * The Laravel layer binds this to the append-only audit table; tests bind an
 * in-memory sink and assert on it. Contexts must already be free of secrets —
 * the sink is the last line, not the first, so callers pass identifiers,
 * codes and timestamps, never tokens.
 */
interface KsefAuditSink
{
    /** @param array<string,mixed> $context */
    public function record(string $action, array $context = [], string $result = 'ok', ?string $error = null): void;
}
