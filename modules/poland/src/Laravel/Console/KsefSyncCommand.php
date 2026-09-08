<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\KsefIngestService;

/**
 * php artisan poland:ksef-sync [--profile=ID] [--subject=Subject2,Subject1]
 *
 * Incremental retrieval from KSeF. A failure exits non-zero and says so; it
 * never prints "0 invoices".
 */
final class KsefSyncCommand extends Command
{
    protected $signature = 'poland:ksef-sync {--profile= : ID profilu podatnika (domyślnie wszystkie)} {--subject= : Typy podmiotu, po przecinku (domyślnie z konfiguracji)}';

    protected $description = 'Pobierz przyrostowo faktury z KSeF (metadane + XML), strona po stronie, z bezpiecznym kursorem.';

    public function handle(KsefIngestService $ingest): int
    {
        $profiles = $this->option('profile') !== null
            ? TaxProfileModel::query()->whereKey((int) $this->option('profile'))->get()
            : TaxProfileModel::query()->orderBy('id')->get();
        $subjects = $this->option('subject') !== null
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('subject')))))
            : null;

        $failed = false;
        foreach ($profiles as $profile) {
            $reason = $ingest->unavailableReason($profile);
            if ($reason !== null) {
                $this->warn(sprintf('[%s] pominięto: %s', $profile->name, $reason));
                $failed = true;

                continue;
            }
            try {
                foreach ($ingest->syncIncremental($profile, $subjects, 'cli') as $report) {
                    $this->line(sprintf('[%s] %s', $profile->name, $report->summary()));
                    $failed = $failed || ! $report->completed;
                }
            } catch (\Throwable $e) {
                $this->error(sprintf('[%s] %s', $profile->name, $e->getMessage()));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
