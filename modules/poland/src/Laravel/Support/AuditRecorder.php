<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Database\Eloquent\Model;
use Poland\Laravel\Models\AuditEventModel;

/**
 * Writes the audit trail.
 *
 * Every entry names an actor. When no authenticated user is present the actor
 * is recorded as the system job that acted — never left blank, because "who"
 * is the question an audit trail exists to answer.
 */
final class AuditRecorder
{
    public const INVOICE_CREATED = 'invoice.created';

    public const SALES_RECORDED = 'sales.recorded';

    public const SALES_CORRECTED = 'sales.corrected';

    public const PURCHASES_RECORDED = 'purchases.recorded';

    public const SETTLEMENT_COMPUTED = 'settlement.computed';

    public const DOCUMENT_PREPARED = 'document.prepared';

    public const DOCUMENT_SUBMITTED = 'document.submitted';

    public const SETTLEMENT_FILED = 'settlement.filed';

    public const PROFILE_CHANGED = 'profile.changed';

    public const REPORT_GENERATED = 'report.generated';

    public const PAYMENT_RECORDED = 'payment.recorded';

    public const PERIOD_CLOSED = 'period.closed';

    public const PERIOD_REOPENED = 'period.reopened';

    /**
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public function record(
        string $action,
        ?int $taxProfileId = null,
        ?Model $subject = null,
        ?string $period = null,
        ?array $old = null,
        ?array $new = null,
        string $result = 'ok',
        ?string $error = null,
        string $source = 'web',
    ): AuditEventModel {
        [$actorId, $actorLabel, $actorIp] = $this->actor($source);

        return AuditEventModel::create([
            'tax_profile_id' => $taxProfileId,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'period' => $period,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel,
            'actor_ip' => $actorIp,
            'old_value' => $old,
            'new_value' => $new,
            'result' => $result,
            'error' => $error,
            'source' => $source,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{0: int|null, 1: string, 2: string|null} */
    private function actor(string $source): array
    {
        $user = null;
        if (function_exists('auth')) {
            try {
                $user = auth()->user();
            } catch (\Throwable) {
                $user = null;
            }
        }

        if ($user !== null) {
            return [
                (int) $user->getAuthIdentifier(),
                (string) ($user->email ?? $user->name ?? 'user#'.$user->getAuthIdentifier()),
                $this->ip(),
            ];
        }

        return [null, 'system:'.$source, $this->ip()];
    }

    private function ip(): ?string
    {
        if (! function_exists('request')) {
            return null;
        }

        try {
            return request()?->ip();
        } catch (\Throwable) {
            return null;
        }
    }
}
