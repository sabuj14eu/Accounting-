<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\KsefScope;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Support\AuditRecorder;
use Poland\Laravel\Support\KsefClientFactory;

/**
 * php artisan poland:ksef-token [--profile=] [--environment=test] [--valid-until=YYYY-MM-DD] [--remove]
 *
 * Stores the KSeF token the taxpayer generated for this application —
 * InvoiceRead only — encrypted at rest, under the environment the token was
 * issued for. The token is read from a hidden prompt, never from an argument
 * (arguments land in shell history and process lists) and never echoed.
 *
 * A PRODUCTION token is refused unless `--go-live-passed-on=YYYY-MM-DD`
 * names the day `poland:ksef-check` passed on the test environment. That
 * date is written to the audit log with the operator's name.
 */
final class KsefTokenCommand extends Command
{
    use ResolvesProfile;

    protected $signature = 'poland:ksef-token
        {--profile= : ID profilu podatnika (domyślnie jedyny istniejący)}
        {--environment= : test | demo | production (domyślnie KSEF_ENVIRONMENT)}
        {--valid-until= : Data ważności tokenu (YYYY-MM-DD), jeśli KSeF ją podał}
        {--go-live-passed-on= : Wymagane dla production: data, kiedy poland:ksef-check przeszedł na środowisku testowym}
        {--remove : Usuń zapisany token dla tego środowiska}';

    protected $description = 'Zapisz (zaszyfrowany) token KSeF InvoiceRead dla podatnika — nigdy nie wypisuje tokenu.';

    public function handle(KsefClientFactory $factory, AuditRecorder $audit): int
    {
        $profile = $this->resolveProfile();
        if ($profile === null) {
            return self::FAILURE;
        }

        $environment = KsefEnvironment::tryFrom((string) ($this->option('environment') ?? config('poland.ksef.environment', 'test')));
        if ($environment === null) {
            $this->error('Środowisko musi być jednym z: test, demo, production.');

            return self::FAILURE;
        }

        $nip = preg_replace('/\D/', '', (string) $profile->nip) ?? '';
        if (strlen($nip) !== 10) {
            $this->error('Profil podatnika nie ma 10-cyfrowego NIP — uzupełnij go najpierw (Profil podatnika).');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $deleted = KsefCredentialModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('environment', $environment->value)
                ->delete();
            $audit->record(AuditRecorder::KSEF_TOKEN_REMOVED, (int) $profile->getKey(), null, null, null, ['environment' => $environment->value, 'deleted' => $deleted], 'ok', null, 'cli');
            $this->info($deleted > 0 ? 'Token usunięty.' : 'Nie było zapisanego tokenu dla tego środowiska.');

            return self::SUCCESS;
        }

        if ($environment->isProduction()) {
            $passedOn = (string) ($this->option('go-live-passed-on') ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $passedOn) !== 1) {
                $this->error(
                    'Token PRODUKCYJNY wymaga --go-live-passed-on=YYYY-MM-DD: daty, kiedy `poland:ksef-check` '
                    .'zakończył się PASS na środowisku testowym. Bez tego produkcja pozostaje wyłączona.',
                );

                return self::FAILURE;
            }
        }

        $this->warn(sprintf('Środowisko: %s. Token zostanie zaszyfrowany i zapisany dla NIP %s.', $environment->label(), $nip));
        $this->line('Token musi mieć WYŁĄCZNIE uprawnienie InvoiceRead. Token z InvoiceWrite lub CredentialsManage — unieważnij i wygeneruj nowy.');

        $token = (string) $this->secret('Wklej token KSeF (nie będzie wyświetlony)');
        $token = trim($token);
        if ($token === '' || strlen($token) < 16) {
            $this->error('Pusty lub zbyt krótki token — nic nie zapisano.');

            return self::FAILURE;
        }

        $validUntil = $this->option('valid-until') !== null
            ? \Carbon\CarbonImmutable::createFromFormat('Y-m-d', (string) $this->option('valid-until'))?->endOfDay()
            : null;

        $credential = KsefCredentialModel::query()->updateOrCreate(
            ['tax_profile_id' => $profile->getKey(), 'environment' => $environment->value],
            [
                'nip' => $nip,
                'base_url' => (string) (config('poland.egress.ksef.base_urls.'.$environment->value) ?? ''),
                'token_encrypted' => $token,
                'scope' => KsefScope::InvoiceRead->value,
                'token_valid_until' => $validUntil,
                'last_verified_at' => null,
                'last_error' => null,
                'enabled' => true,
            ],
        );

        $audit->record(
            AuditRecorder::KSEF_TOKEN_STORED,
            (int) $profile->getKey(),
            $credential,
            null,
            null,
            [
                'environment' => $environment->value,
                'scope' => KsefScope::InvoiceRead->value,
                'token_valid_until' => $validUntil?->toDateString(),
                'token_fingerprint' => substr(hash('sha256', $token), 0, 12),
                'go_live_passed_on' => $environment->isProduction() ? $this->option('go-live-passed-on') : null,
            ],
            'ok',
            null,
            'cli',
        );

        $this->info(sprintf('Zapisano token dla środowiska %s (odcisk %s). Uruchom `php artisan poland:ksef-check`.', $environment->value, substr(hash('sha256', $token), 0, 12)));

        $reason = $factory->unavailableReason($profile);
        if ($reason !== null) {
            $this->warn('Uwaga: synchronizacja nadal niedostępna — '.$reason);
        }

        return self::SUCCESS;
    }
}
