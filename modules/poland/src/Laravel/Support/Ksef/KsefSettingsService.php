<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\KsefScope;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Models\TaxProfileModel;

/**
 * The step-by-step configuration behind "Konfiguruj KSeF".
 *
 * One row per (profile, environment); the environment is the deployment's
 * (KSEF_ENVIRONMENT), never chosen per profile — a token for TEST must never
 * be used against PRODUCTION and the row's environment column is the proof
 * of which one it belongs to.
 */
final class KsefSettingsService
{
    public function __construct(
        private readonly KsefTransportFactory $transports,
        private readonly LaravelKsefAuditSink $audit,
    ) {
    }

    public function current(TaxProfileModel $profile): ?KsefCredentialModel
    {
        return KsefCredentialModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('environment', $this->transports->environment()->value)
            ->first();
    }

    /** The current row, or an unsaved instance with defaults — a GET must not create rows. */
    public function draft(TaxProfileModel $profile): KsefCredentialModel
    {
        $current = $this->current($profile);
        if ($current !== null) {
            return $current;
        }
        $environment = $this->transports->environment();

        return new KsefCredentialModel([
            'tax_profile_id' => $profile->getKey(),
            'environment' => $environment->value,
            'nip' => (string) ($profile->nip ?? ''),
            'base_url' => (string) config('poland.ksef.base_urls.'.$environment->value, ''),
            'scope' => KsefScope::InvoiceRead->value,
            'enabled' => false,
            'system_info' => (string) config('poland.ksef.system_info', 'SignalMesh Accounts'),
            'api_version' => $this->transports->apiVersion(),
            'seller_address_country' => 'PL',
            'setup_step' => 0,
        ]);
    }

    public function ensure(TaxProfileModel $profile): KsefCredentialModel
    {
        $environment = $this->transports->environment();

        return KsefCredentialModel::query()->firstOrCreate(
            ['tax_profile_id' => $profile->getKey(), 'environment' => $environment->value],
            [
                'nip' => (string) ($profile->nip ?? ''),
                'base_url' => (string) config('poland.ksef.base_urls.'.$environment->value, ''),
                'scope' => KsefScope::InvoiceRead->value,
                'enabled' => false,
                'system_info' => (string) config('poland.ksef.system_info', 'SignalMesh Accounts'),
                'api_version' => $this->transports->apiVersion(),
                'seller_address_country' => 'PL',
            ],
        );
    }

    /** @param array<string,mixed> $data validated input of one wizard step */
    public function saveStep(KsefCredentialModel $credential, int $step, array $data, string $actor): KsefCredentialModel
    {
        $changed = [];
        switch ($step) {
            case KsefCredentialModel::STEP_ENVIRONMENT:
                // The environment is the deployment's; the step only confirms it.
                $credential->api_version = $this->transports->apiVersion();
                $credential->base_url = (string) config('poland.ksef.base_urls.'.$credential->environment, '');
                $changed = ['environment' => $credential->environment, 'api_version' => $credential->api_version];
                break;

            case KsefCredentialModel::STEP_SELLER:
                foreach (['nip', 'seller_address_country', 'seller_address_line1', 'seller_address_line2', 'seller_email', 'seller_phone', 'issue_place', 'system_info'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $value = $data[$field];
                        $credential->{$field} = is_string($value) && trim($value) === '' ? null : $value;
                        $changed[$field] = $credential->{$field};
                    }
                }
                $credential->nip = preg_replace('/\D/', '', (string) $credential->nip) ?? '';
                break;

            case KsefCredentialModel::STEP_TOKEN:
                if (! empty($data['token'])) {
                    $credential->storeToken((string) $data['token']);
                    $changed['token_fingerprint'] = $credential->token_fingerprint;
                    $this->audit->forProfile((int) $credential->tax_profile_id)->record(KsefAuditActions::TOKEN_STORED, [
                        'environment' => $credential->environment,
                        'token_fingerprint' => $credential->token_fingerprint,
                        'actor' => $actor,
                    ]);
                }
                $credential->token_reference = isset($data['token_reference']) && trim((string) $data['token_reference']) !== '' ? trim((string) $data['token_reference']) : null;
                $credential->token_valid_until = ! empty($data['token_valid_until']) ? $data['token_valid_until'] : null;
                $declared = array_values(array_filter((array) ($data['token_permissions'] ?? []), 'is_string'));
                $credential->token_permissions = $declared;
                $credential->scope = in_array(KsefScope::InvoiceWrite->value, $declared, true) ? KsefScope::InvoiceWrite->value : KsefScope::InvoiceRead->value;
                $changed += ['token_reference' => $credential->token_reference, 'token_permissions' => $declared];
                break;

            case KsefCredentialModel::STEP_INVOICE_DEFAULTS:
                foreach (['exemption_legal_basis', 'default_payment_form', 'bank_account', 'bank_name'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $value = $data[$field];
                        $credential->{$field} = is_string($value) && trim($value) === '' ? null : $value;
                        $changed[$field] = $credential->{$field};
                    }
                }
                if ($credential->bank_account !== null) {
                    $credential->bank_account = strtoupper((string) preg_replace('/\s+/', '', (string) $credential->bank_account));
                }
                break;

            case KsefCredentialModel::STEP_TESTED:
                $enable = (bool) ($data['enabled'] ?? false);
                if ($enable && $credential->last_connection_ok !== true) {
                    throw new \RuntimeException('Integrację można włączyć dopiero po udanym teście połączenia.');
                }
                if ($enable && $this->transports->environment()->isProduction() && $credential->production_enabled_at === null) {
                    $credential->production_enabled_at = now();
                    $credential->production_enabled_by = $actor;
                }
                $credential->enabled = $enable;
                $changed['enabled'] = $enable;
                break;

            default:
                throw new \InvalidArgumentException('Nieznany krok konfiguracji: '.$step);
        }

        $credential->setup_step = max((int) $credential->setup_step, $step);
        $credential->save();

        $this->audit->forProfile((int) $credential->tax_profile_id)->record(KsefAuditActions::SETTINGS_CHANGED, [
            'environment' => $credential->environment,
            'step' => $step,
            'changed' => $changed,
            'actor' => $actor,
        ]);

        return $credential;
    }
}
