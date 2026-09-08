<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\Ksef\KsefConnectionService;

/**
 * php artisan poland:ksef-test [--profile=ID]
 *
 * A real authentication against the configured KSeF environment. Sends no
 * invoice. Exit code 0 only when CONNECTED.
 */
final class KsefTestCommand extends Command
{
    protected $signature = 'poland:ksef-test {--profile= : ID profilu podatnika (domyślnie pierwszy)}';

    protected $description = 'Przetestuj połączenie z KSeF (rzeczywiste uwierzytelnienie, bez wysyłki faktur).';

    public function handle(KsefConnectionService $connections): int
    {
        $profile = $this->option('profile') !== null
            ? TaxProfileModel::query()->find((int) $this->option('profile'))
            : TaxProfileModel::query()->orderBy('id')->first();
        if ($profile === null) {
            $this->error('Brak profilu podatnika.');

            return self::FAILURE;
        }

        $health = $connections->test($profile, 'cli:'.(get_current_user() ?: 'artisan'));
        $this->line(sprintf('KSeF [%s]: %s', $health->mode, $health->overall));
        foreach ($health->checks as $check) {
            $this->line(sprintf('  %-26s %-8s %s', $check->name, $check->state, $check->detail));
        }

        return $health->isConnected() ? self::SUCCESS : self::FAILURE;
    }
}
