<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use DateTimeImmutable;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Health\KsefHealth;
use Poland\Ksef\Outgoing\KsefSubmissionState;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\KsefErrorModel;
use Poland\Laravel\Models\KsefSubmissionModel;
use Poland\Laravel\Models\KsefSyncRunModel;
use Poland\Laravel\Models\TaxProfileModel;

/**
 * "Test KSeF Connection" and the truthful status panel.
 *
 * A test is a real authentication against the configured environment. It
 * sends no invoice. Its result is stored with its timestamp, and the status
 * panel derives CONNECTED only from a fresh successful authentication —
 * never from the existence of credentials.
 */
final class KsefConnectionService
{
    public function __construct(
        private readonly KsefTransportFactory $transports,
        private readonly KsefSettingsService $settings,
        private readonly KsefTokenService $tokens,
        private readonly LaravelKsefAuditSink $audit,
        private readonly KsefErrorRecorder $errors,
    ) {
    }

    public function test(TaxProfileModel $profile, string $actor): KsefHealth
    {
        $credential = $this->settings->ensure($profile);
        $gate = $this->transports->gate();
        $audit = $this->audit->forProfile((int) $profile->getKey());
        $now = now()->toDateTimeImmutable();

        $facts = [
            'mode' => $gate->mode(),
            'configured' => $credential->isConfigured(),
            'configured_detail' => $credential->isConfigured() ? null : 'Brakuje: '.implode(', ', $credential->missingConfiguration()),
            'now' => $now,
            'freshness_hours' => (int) config('poland.ksef.auth.freshness_hours', 24),
        ];

        if (! $gate->isEnabled()) {
            $facts['api_reachable'] = null;
            $facts['authenticated'] = null;
            $health = KsefHealth::evaluate($facts);
            $audit->record(KsefAuditActions::CONNECTION_TESTED, ['environment' => $credential->environment, 'actor' => $actor, 'result' => $health->overall, 'mode' => $gate->mode()], 'failed', 'transport wyłączony');

            return $health;
        }

        if (! $credential->isConfigured()) {
            $health = KsefHealth::evaluate($facts + ['api_reachable' => null, 'authenticated' => null]);
            $audit->record(KsefAuditActions::CONNECTION_TESTED, ['environment' => $credential->environment, 'actor' => $actor, 'result' => $health->overall], 'failed', 'niekompletna konfiguracja');

            return $health;
        }

        $transport = $this->transports->transport();

        // 1. API reachable: the public-key endpoint needs no credential.
        try {
            $certificates = $transport->publicKeyCertificates();
            $facts['api_reachable'] = true;
            $facts['api_checked_at'] = $now;
            $facts['api_detail'] = sprintf('API %s odpowiedziało (%d certyfikatów kluczy publicznych).', $gate->environment()->shortLabel(), count($certificates));
        } catch (KsefException $e) {
            $this->errors->record($e, (int) $profile->getKey());
            $facts['api_reachable'] = false;
            $facts['api_checked_at'] = $now;
            $facts['api_detail'] = $e->getMessage();
            $facts['authenticated'] = null;
            $this->remember($credential, false, $e->getMessage(), $now);
            $health = KsefHealth::evaluate($facts);
            $audit->record(KsefAuditActions::CONNECTION_TESTED, ['environment' => $credential->environment, 'actor' => $actor, 'result' => $health->overall] + $e->toArray(), 'failed', $e->getMessage());

            return $health;
        }

        // 2. Authenticated: a fresh, real authentication with the stored token.
        try {
            $context = $this->tokens->context($credential, fresh: true);
            $facts['authenticated'] = true;
            $facts['authenticated_at'] = $now;
            $missing = $credential->permissionsMissing();
            $facts['auth_detail'] = sprintf(
                'Uwierzytelniono (metoda: %s). Uprawnienia tokena: %s.%s',
                $context->authenticationMethod ?? 'nieznana',
                $context->permissions === [] ? 'nie odczytano z tokena' : implode(', ', $context->permissions),
                $missing !== [] ? ' BRAKUJE: '.implode(', ', $missing).' — wystawianie/odczyt nie zadziała bez nich.' : '',
            );
            $this->remember($credential, true, $facts['auth_detail'], $now);
        } catch (KsefException $e) {
            $facts['authenticated'] = false;
            $facts['authenticated_at'] = $now;
            $facts['auth_detail'] = $e->getMessage();
            $this->remember($credential, false, $e->getMessage(), $now);
        }

        $facts += $this->syncFacts($credential);
        $health = KsefHealth::evaluate($facts);
        $audit->record(
            KsefAuditActions::CONNECTION_TESTED,
            ['environment' => $credential->environment, 'actor' => $actor, 'result' => $health->overall, 'checks' => $health->checks],
            $health->isConnected() ? 'ok' : 'failed',
            $health->isConnected() ? null : ($facts['auth_detail'] ?? $facts['api_detail'] ?? null),
        );

        return $health;
    }

    /** Facts only; no network. */
    public function health(TaxProfileModel $profile): KsefHealth
    {
        $credential = $this->settings->current($profile);
        $gate = $this->transports->gate();
        $now = now()->toDateTimeImmutable();

        $facts = [
            'mode' => $gate->mode(),
            'configured' => $credential !== null && $credential->isConfigured(),
            'configured_detail' => $credential === null ? 'Nie rozpoczęto konfiguracji KSeF.' : ($credential->isConfigured() ? null : 'Brakuje: '.implode(', ', $credential->missingConfiguration())),
            'now' => $now,
            'freshness_hours' => (int) config('poland.ksef.auth.freshness_hours', 24),
        ];
        if ($credential !== null) {
            $facts['api_reachable'] = $credential->last_connection_test_at !== null ? ($credential->last_connection_ok === true || $credential->last_auth_at !== null) : null;
            $facts['api_checked_at'] = $credential->last_connection_test_at?->toDateTimeImmutable();
            $facts['api_detail'] = $credential->last_connection_ok === false ? (string) $credential->last_connection_detail : null;
            $facts['authenticated'] = $credential->last_connection_test_at === null ? null : $credential->last_connection_ok;
            $facts['authenticated_at'] = ($credential->last_auth_at ?? $credential->last_connection_test_at)?->toDateTimeImmutable();
            $facts['auth_detail'] = $credential->last_connection_detail;
            $facts += $this->syncFacts($credential);
        }

        return KsefHealth::evaluate($facts);
    }

    /** @return array<string,mixed> counts for the status page; each a fact with a source */
    public function overview(TaxProfileModel $profile): array
    {
        $credential = $this->settings->current($profile);
        $gate = $this->transports->gate();
        $states = KsefSubmissionModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->selectRaw('state, count(*) as n')
            ->groupBy('state')
            ->pluck('n', 'state')
            ->all();

        return [
            'mode' => $gate->mode(),
            'environment' => $gate->environment(),
            'transport_kind' => $gate->kind(),
            'transport_enabled' => $gate->isEnabled(),
            'api_version' => $this->transports->apiVersion(),
            'credential' => $credential,
            'incoming_count' => KsefDocumentModel::query()->where('tax_profile_id', $profile->getKey())->incoming()->count(),
            'incoming_review_count' => KsefDocumentModel::query()->where('tax_profile_id', $profile->getKey())->incoming()->needingReview()->count(),
            'outgoing_by_state' => $states,
            'outgoing_accepted' => (int) ($states[KsefSubmissionState::Accepted->value] ?? 0),
            'outgoing_pending' => (int) ($states[KsefSubmissionState::Submitted->value] ?? 0) + (int) ($states[KsefSubmissionState::Processing->value] ?? 0),
            'outgoing_review' => (int) ($states[KsefSubmissionState::ManualReview->value] ?? 0),
            'outgoing_rejected' => (int) ($states[KsefSubmissionState::Rejected->value] ?? 0),
            'failed_operations_30d' => KsefErrorModel::query()->where('tax_profile_id', $profile->getKey())->where('occurred_at', '>=', now()->subDays(30))->count(),
            'last_error' => KsefErrorModel::query()->where('tax_profile_id', $profile->getKey())->orderByDesc('occurred_at')->first(),
            'last_sync_run' => KsefSyncRunModel::query()->where('tax_profile_id', $profile->getKey())->orderByDesc('started_at')->first(),
        ];
    }

    /** @return array<string,mixed> */
    private function syncFacts(KsefCredentialModel $credential): array
    {
        $run = KsefSyncRunModel::query()
            ->where('tax_profile_id', $credential->tax_profile_id)
            ->where('environment', $credential->environment)
            ->orderByDesc('started_at')
            ->first();
        if ($run === null) {
            return ['last_sync_ok' => null];
        }

        return [
            'last_sync_ok' => $run->status === 'COMPLETED',
            'last_sync_at' => $run->started_at?->toDateTimeImmutable(),
            'sync_detail' => $run->status === 'COMPLETED'
                ? sprintf('Ostatnia synchronizacja (%s): %d nowych, %d duplikatów, %d do przeglądu.', $run->subject_type, $run->imported_count, $run->duplicate_count, $run->review_count)
                : sprintf('Ostatnia synchronizacja (%s) zakończyła się stanem %s: %s', $run->subject_type, $run->status, (string) $run->stopped_because),
        ];
    }

    private function remember(KsefCredentialModel $credential, bool $ok, string $detail, DateTimeImmutable $at): void
    {
        $credential->forceFill([
            'last_connection_test_at' => $at,
            'last_connection_ok' => $ok,
            'last_connection_detail' => mb_substr($detail, 0, 2000),
            'last_error' => $ok ? null : mb_substr($detail, 0, 2000),
        ])->save();
    }
}
