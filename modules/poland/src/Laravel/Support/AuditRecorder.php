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

    public const KSEF_SYNCED = 'ksef.synced';

    public const KSEF_INVOICE_IMPORTED = 'ksef.invoice_imported';

    public const REPORT_REQUIRES_REVIEW = 'report.requires_review';

    public const STATEMENT_IMPORTED = 'bank.statement_imported';

    public const TRANSACTION_CLASSIFIED = 'bank.transaction_classified';

    public const MATCH_DECIDED = 'reconciliation.match_decided';

    public const GOVERNMENT_DOCUMENT_RECEIVED = 'government.document_received';

    public const PERIOD_CLOSED = 'period.closed';

    public const PERIOD_REOPENED = 'period.reopened';

    public const KSEF_INVOICE_AWAITING_REVIEW = 'ksef.invoice_awaiting_review';

    public const KSEF_INVOICE_APPROVED = 'ksef.invoice_approved';

    public const KSEF_INVOICE_REJECTED = 'ksef.invoice_rejected';

    public const PURCHASE_POSTED = 'purchase.posted';

    public const PRODUCT_CREATED = 'product.created';

    public const PRODUCT_MAPPED = 'product.mapped';

    public const INVENTORY_TRACKING_CHANGED = 'inventory.tracking_changed';

    public const INVENTORY_MOVEMENT_RECORDED = 'inventory.movement_recorded';

    public const INVENTORY_COUNT_RECORDED = 'inventory.count_recorded';

    public const PLATFORM_SETTLEMENT_RECORDED = 'platform.settlement_recorded';

    public const PURCHASE_SUMMARY_SUPERSEDED = 'purchases.summary_superseded';

    public const KSEF_TOKEN_STORED = 'ksef.token_stored';

    public const KSEF_TOKEN_REMOVED = 'ksef.token_removed';

    public const KSEF_CHECK_RUN = 'ksef.check_run';

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
