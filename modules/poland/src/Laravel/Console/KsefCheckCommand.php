<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Ksef\GoLiveChecklist;
use Poland\Ksef\HttpKsefClient;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\TransportGate;
use Poland\Laravel\Support\AuditRecorder;
use Poland\Laravel\Support\KsefClientFactory;

/**
 * php artisan poland:ksef-check [--profile=] [--json]
 *
 * Runs the 12-step go-live checklist (docs/PRODUCTION_AUDIT_2026-09-07.md §1)
 * against the configured KSeF environment with the stored token, prints one
 * verdict per step, records the run in the audit log, and marks the credential
 * verified or records the failure on it. Imports nothing.
 *
 * Exit code 0 only when every step is PASS. NOT TESTED is not PASS.
 */
final class KsefCheckCommand extends Command
{
    use ResolvesProfile;

    protected $signature = 'poland:ksef-check
        {--profile= : ID profilu podatnika (domyślnie jedyny istniejący)}
        {--json : Wypisz wynik jako JSON}';

    protected $description = 'Lista kontrolna go-live KSeF (12 kroków) na żywo, na skonfigurowanym środowisku.';

    public function handle(KsefClientFactory $factory, FaInvoiceParser $parser, AuditRecorder $audit): int
    {
        $profile = $this->resolveProfile();
        if ($profile === null) {
            return self::FAILURE;
        }

        try {
            $gate = $factory->gate();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $gate->isEnabled() || $gate->kind() !== TransportGate::REAL) {
            $this->error(sprintf(
                'Lista kontrolna wymaga KSEF_TRANSPORT=real i KSEF_TRANSPORT_ENABLED=true (teraz: %s, %s).',
                $gate->kind(),
                $gate->isEnabled() ? 'włączony' : 'wyłączony',
            ));

            return self::FAILURE;
        }

        $this->warn('Środowisko: '.$gate->environment()->label());

        try {
            $client = $factory->forProfile($profile, 10);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! $client instanceof HttpKsefClient) {
            $this->error('Zbudowany klient nie jest klientem HTTP — lista kontrolna nie ma czego sprawdzać.');

            return self::FAILURE;
        }

        $checklist = new GoLiveChecklist(
            fn (int $pageSize, ?string $token) => $factory->forProfile($profile, $pageSize, $token),
            $factory->transport(),
            $client->endpoint(),
            $parser,
            (string) config('poland.ksef.api_version', ''),
            (int) config('poland.ksef.lookback_days', 45),
        );

        $results = $checklist->run();
        $passed = GoLiveChecklist::allPassed($results);

        if ($this->option('json')) {
            $this->line(json_encode(['environment' => $gate->environment()->value, 'passed' => $passed, 'steps' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['#', 'Krok', 'Werdykt', 'Zaobserwowano'],
                array_map(static fn (array $r): array => [$r['step'], $r['name'], $r['verdict'], wordwrap($r['observed'], 70, "\n", true)], $results),
            );
            $this->line('Podsumowanie: '.GoLiveChecklist::summary($results));
        }

        // Credential bookkeeping: step 4 decides "verified".
        $credential = null;
        try {
            $credential = $factory->credentialFor($profile);
        } catch (\RuntimeException) {
        }
        $auth = $results[3] ?? null;
        if ($credential !== null && $auth !== null) {
            $credential->forceFill($auth['verdict'] === GoLiveChecklist::PASS
                ? ['last_verified_at' => now(), 'last_error' => null]
                : ['last_error' => $auth['observed']])->save();
        }

        $audit->record(
            AuditRecorder::KSEF_CHECK_RUN,
            (int) $profile->getKey(),
            $credential,
            null,
            null,
            ['environment' => $gate->environment()->value, 'passed' => $passed, 'summary' => GoLiveChecklist::summary($results), 'steps' => $results],
            $passed ? 'ok' : 'failed',
            $passed ? null : 'nie wszystkie kroki PASS',
            'cli',
        );

        if ($passed) {
            $this->info('Wszystkie 12 kroków PASS na środowisku '.$gate->environment()->value.'. Zapisz ten wynik w docs/KSEF_GO_LIVE.md zanim przełączysz środowisko.');

            return self::SUCCESS;
        }

        $this->error('Lista kontrolna NIE przeszła w całości. NOT TESTED to nie PASS — patrz kolumna "Zaobserwowano".');

        return self::FAILURE;
    }
}
