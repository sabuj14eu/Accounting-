<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Poland\Ksef\HttpKsefClient;
use Poland\Laravel\Support\KsefIngestService;

/**
 * php artisan poland:ksef-sync [--profile=] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
 *
 * Pulls incoming invoices received in the window (default: the last
 * `lookback_days`) into the review inbox. Runs from the scheduler and by hand.
 * Exit code is non-zero when the run was not clean, so a cron wrapper can
 * tell "ran and found nothing new" from "could not check".
 */
final class KsefSyncCommand extends Command
{
    use ResolvesProfile;

    protected $signature = 'poland:ksef-sync
        {--profile= : ID profilu podatnika (domyślnie jedyny istniejący)}
        {--from= : Początek okna (YYYY-MM-DD); domyślnie dziś minus lookback_days}
        {--to= : Koniec okna (YYYY-MM-DD); domyślnie teraz}';

    protected $description = 'Pobierz nowe faktury zakupowe z KSeF do skrzynki przeglądu.';

    public function handle(KsefIngestService $ingest): int
    {
        $profile = $this->resolveProfile();
        if ($profile === null) {
            return self::FAILURE;
        }

        $reason = $ingest->unavailableReason($profile);
        if ($reason !== null) {
            $this->error('Synchronizacja niedostępna: '.$reason);
            $this->line('To NIE jest informacja o braku faktur — system nie zdołał ich sprawdzić.');

            return self::FAILURE;
        }

        $utc = new DateTimeZone('UTC');
        $to = $this->option('to') !== null
            ? new DateTimeImmutable((string) $this->option('to').' 23:59:59', $utc)
            : new DateTimeImmutable('now', $utc);
        $from = $this->option('from') !== null
            ? new DateTimeImmutable((string) $this->option('from').' 00:00:00', $utc)
            : $to->modify(sprintf('-%d days', (int) config('poland.ksef.lookback_days', 45)));

        if ((int) $from->diff($to)->format('%a') > HttpKsefClient::MAX_WINDOW_DAYS) {
            $this->error(sprintf('Okno przekracza %d dni dopuszczane przez API — podaj węższy zakres.', HttpKsefClient::MAX_WINDOW_DAYS));

            return self::FAILURE;
        }

        try {
            $result = $ingest->sync($profile, $from, $to, (int) config('poland.ksef.max_invoices_per_run', 500));
        } catch (\Throwable $e) {
            $this->error('Synchronizacja z KSeF niedostępna: '.$e->getMessage());
            $this->line('To NIE jest informacja o braku faktur.');

            return self::FAILURE;
        }

        $this->line(sprintf('Okno %s → %s (UTC).', $from->format('Y-m-d H:i'), $to->format('Y-m-d H:i')));
        $this->line($result->summary());
        foreach ($result->failures as $number => $why) {
            $this->warn('  '.$number.': '.$why);
        }
        if ($result->affectedPeriods !== []) {
            $this->warn('Miesiące z już wygenerowanym raportem, które dostały nowe faktury: '.implode(', ', $result->affectedPeriods));
        }

        return $result->isClean() ? self::SUCCESS : self::FAILURE;
    }
}
