<?php

declare(strict_types=1);

namespace Poland\Ksef\Audit;

final class NullAuditSink implements KsefAuditSink
{
    public function record(string $action, array $context = [], string $result = 'ok', ?string $error = null): void
    {
    }
}
