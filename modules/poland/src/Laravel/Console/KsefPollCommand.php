<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\Ksef\KsefSubmissionService;

/**
 * php artisan poland:ksef-poll
 *
 * Checks every SUBMITTED/PROCESSING invoice against KSeF and fetches the UPO
 * of the ones accepted. Scheduled every five minutes; safe to run by hand.
 */
final class KsefPollCommand extends Command
{
    protected $signature = 'poland:ksef-poll {--profile= : ID profilu podatnika (domyślnie wszystkie)}';

    protected $description = 'Sprawdź status faktur wysłanych do KSeF i pobierz UPO dla przyjętych.';

    public function handle(KsefSubmissionService $submissions): int
    {
        $profiles = $this->option('profile') !== null
            ? TaxProfileModel::query()->whereKey((int) $this->option('profile'))->get()
            : TaxProfileModel::query()->orderBy('id')->get();
        $total = 0;
        foreach ($profiles as $profile) {
            $count = $submissions->pollPending($profile);
            $total += $count;
            $this->line(sprintf('[%s] sprawdzono %d zgłoszeń', $profile->name, $count));
        }
        $this->line('Razem: '.$total);

        return self::SUCCESS;
    }
}
