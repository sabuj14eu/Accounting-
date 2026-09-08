<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use Poland\Ksef\Audit\KsefAuditSink;
use Poland\Ksef\Error\Redactor;
use Poland\Laravel\Support\AuditRecorder;

/** Writes KSeF events into the append-only `pl_audit_events`, scrubbed once more on the way. */
final class LaravelKsefAuditSink implements KsefAuditSink
{
    private ?int $taxProfileId = null;

    private ?string $period = null;

    public function __construct(private readonly AuditRecorder $audit)
    {
    }

    public function forProfile(?int $taxProfileId, ?string $period = null): self
    {
        $clone = clone $this;
        $clone->taxProfileId = $taxProfileId;
        $clone->period = $period;

        return $clone;
    }

    public function record(string $action, array $context = [], string $result = 'ok', ?string $error = null): void
    {
        $redactor = Redactor::none();
        $safe = json_decode($redactor->scrub(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'), true);

        $this->audit->record(
            $action,
            $this->taxProfileId,
            null,
            $this->period,
            null,
            is_array($safe) ? $safe : ['context' => '[unserialisable]'],
            $result,
            $error === null ? null : $redactor->scrub($error),
            'ksef',
        );
    }
}
