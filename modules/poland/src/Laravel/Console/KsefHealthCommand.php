<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\Ksef\KsefConnectionService;

/**
 * php artisan poland:ksef-health [--json]
 *
 * The four separate questions (configured, reachable, authenticated, sync
 * healthy) from stored facts — no network. For monitoring.
 */
final class KsefHealthCommand extends Command
{
    protected $signature = 'poland:ksef-health {--profile=} {--json : Wynik jako JSON}';

    protected $description = 'Stan integracji KSeF z zapisanych faktów (bez połączenia z KSeF).';

    public function handle(KsefConnectionService $connections): int
    {
        $profile = $this->option('profile') !== null
            ? TaxProfileModel::query()->find((int) $this->option('profile'))
            : TaxProfileModel::query()->orderBy('id')->first();
        // No taxpayer profile yet: still answer from the gate, because a
        // monitor asking "is KSeF connected?" deserves the truth, not a crash.
        $health = $profile === null
            ? \Poland\Ksef\Health\KsefHealth::evaluate([
                'mode' => app(\Poland\Laravel\Support\Ksef\KsefTransportFactory::class)->gate()->mode(),
                'configured' => false,
                'configured_detail' => 'Brak profilu podatnika.',
                'now' => now()->toDateTimeImmutable(),
            ])
            : $connections->health($profile);
        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        } else {
            $this->line(sprintf('KSeF [%s]: %s', $health->mode, $health->overall));
            foreach ($health->checks as $check) {
                $this->line(sprintf('  %-26s %-8s %s', $check->name, $check->state, $check->detail));
            }
        }

        return $health->isConnected() ? self::SUCCESS : self::FAILURE;
    }
}
