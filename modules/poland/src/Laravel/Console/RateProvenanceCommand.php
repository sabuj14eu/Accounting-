<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Domain\Period;
use Poland\Rates\RateRepository;
use Poland\Rates\VerificationStatus;

/**
 * php artisan poland:rate-provenance [--todo] [--period=2026-08]
 *
 * Prints where every rate came from and, with --todo, exactly what an
 * accountant has to confirm and where. The verification step is the last thing
 * standing between this software and a real filing, so it gets a first-class
 * command rather than a paragraph in a README that nobody opens.
 */
final class RateProvenanceCommand extends Command
{
    protected $signature = 'poland:rate-provenance
        {--todo : Pokaż wyłącznie pozycje wymagające potwierdzenia urzędowego}
        {--period= : Sprawdź tylko wersje obowiązujące dla danego miesiąca}
        {--json : Wypisz JSON}';

    protected $description = 'Pokaż pochodzenie i status weryfikacji każdej stawki.';

    public function handle(RateRepository $rates): int
    {
        $onlyTodo = (bool) $this->option('todo');
        $period = $this->option('period') !== null
            ? Period::parse((string) $this->option('period'))
            : null;

        $rows = [];
        $unverified = 0;

        foreach (RateRepository::TABLES as $name) {
            $table = $rates->table($name);

            foreach ($table->versions() as $version) {
                if ($period !== null && ! $version->covers($period)) {
                    continue;
                }

                $provenance = $version->provenance;
                $fit = $provenance->status->fitForFiling();

                if (! $fit) {
                    $unverified++;
                }

                if ($onlyTodo && $fit) {
                    continue;
                }

                $rows[] = [
                    $name,
                    $version->version,
                    $version->effectiveFrom->toString().' → '.($version->effectiveTo?->toString() ?? '…'),
                    match ($provenance->status) {
                        VerificationStatus::Official => 'URZĘDOWE',
                        VerificationStatus::Secondary => 'wtórne',
                        VerificationStatus::Unverified => 'BRAK',
                    },
                    $provenance->checkedOn,
                ];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $unverified > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('Wszystkie stawki są potwierdzone w źródłach urzędowych.');

            return self::SUCCESS;
        }

        $this->table(['Tabela', 'Wersja', 'Obowiązuje', 'Weryfikacja', 'Sprawdzono'], $rows);

        if ($onlyTodo || $unverified > 0) {
            $this->newLine();
            $this->line('<options=bold>DO POTWIERDZENIA W ŹRÓDLE URZĘDOWYM</>');
            $this->newLine();

            foreach (RateRepository::TABLES as $name) {
                foreach ($rates->table($name)->versions() as $version) {
                    if ($period !== null && ! $version->covers($period)) {
                        continue;
                    }
                    if ($version->provenance->status->fitForFiling()) {
                        continue;
                    }

                    $this->line(sprintf('  <fg=yellow>%s v%s</> (%s → %s)',
                        $name,
                        $version->version,
                        $version->effectiveFrom->toString(),
                        $version->effectiveTo?->toString() ?? '…',
                    ));
                    $this->line('    dokument : '.$version->provenance->sourceDocument);
                    $this->line('    obecnie z: '.($version->provenance->sourceUrl ?: 'brak adresu'));
                    $this->line('    <fg=cyan>sprawdź w: '.$version->provenance->officialSourceUrl.'</>');
                    if ($version->provenance->notes !== '') {
                        $this->line('    uwagi    : '.$version->provenance->notes);
                    }
                    $this->newLine();
                }
            }

            $this->warn(sprintf(
                '%d wersji stawek nie jest potwierdzonych urzędowo. Po potwierdzeniu ustaw '
                .'w config/rates/*.php status "official" wraz z adresem publikacji organu — '
                .'dopóki tego nie zrobisz, produkcja z poland.require_official_rates=true '
                .'odmówi rozliczenia.',
                $unverified,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
