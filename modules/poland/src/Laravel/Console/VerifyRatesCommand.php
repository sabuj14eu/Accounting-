<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Domain\Period;
use Poland\Rates\RateRepository;

/**
 * php artisan poland:verify-rates [--months=6]
 *
 * Rate tables run out. This is the check that says so BEFORE a taxpayer tries
 * to settle a month the software cannot price — run it from a scheduler and
 * treat a failure as a real one.
 */
final class VerifyRatesCommand extends Command
{
    protected $signature = 'poland:verify-rates {--months=6 : Ile miesięcy naprzód sprawdzić}';

    protected $description = 'Sprawdź, czy tabele stawek pokrywają najbliższe miesiące.';

    public function handle(RateRepository $rates): int
    {
        $months = max(1, (int) $this->option('months'));
        $period = Period::of((int) date('Y'), (int) date('n'));

        $rows = [];
        $failed = false;

        for ($i = 0; $i < $months; $i++) {
            $coverage = $rates->coverage($period);
            $rows[] = [
                $period->toString(),
                $coverage['ok'] ? 'OK' : 'BRAK',
                $coverage['ok'] ? '' : implode(', ', $coverage['missing']),
            ];
            $failed = $failed || ! $coverage['ok'];
            $period = $period->next();
        }

        $this->table(['Miesiąc', 'Pokrycie', 'Brakujące tabele'], $rows);

        foreach (RateRepository::TABLES as $name) {
            $table = $rates->table($name);
            if ($table->versions() === []) {
                continue;
            }
            $until = $table->coveredUntil();
            $this->line(sprintf(
                '  %-12s obowiązuje do %s',
                $name,
                $until?->toString() ?? 'bezterminowo',
            ));
        }

        if ($failed) {
            $this->error(
                'Niektóre miesiące nie mają stawek. Uzupełnij config/rates/*.php razem ze '
                .'źródłem — silnik odmówi rozliczenia takiego miesiąca zamiast zgadywać.',
            );

            return self::FAILURE;
        }

        $this->info('Wszystkie sprawdzane miesiące mają komplet stawek.');

        return self::SUCCESS;
    }
}
