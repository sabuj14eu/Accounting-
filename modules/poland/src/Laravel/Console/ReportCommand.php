<?php

declare(strict_types=1);

namespace Poland\Laravel\Console;

use Illuminate\Console\Command;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\SettlementRecorder;
use Poland\Reporting\TextReportRenderer;

/**
 * php artisan poland:report 2026-08 --profile=1 [--sales=48500]
 *
 * The same engine as the web screens and the standalone CLI, so a figure never
 * depends on where it was asked for.
 */
final class ReportCommand extends Command
{
    protected $signature = 'poland:report
        {period : Miesiąc do rozliczenia, np. 2026-08}
        {--profile= : ID profilu podatnika (domyślnie jedyny istniejący)}
        {--sales= : Zapisz sprzedaż brutto z kasy fiskalnej za ten miesiąc przed rozliczeniem}
        {--designation=auto : Oznaczenie VAT dla podanej sprzedaży (np. 0.23, zw)}
        {--reason= : Przyczyna korekty, jeśli sprzedaż za ten miesiąc już istnieje}
        {--json : Wypisz JSON zamiast raportu tekstowego}
        {--brief : Bez szczegółowego wyliczenia}';

    protected $description = 'Wylicz ZUS, VAT i PIT za wskazany miesiąc.';

    public function handle(SettlementRecorder $recorder): int
    {
        try {
            $period = Period::parse((string) $this->argument('period'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $profile = $this->resolveProfile();
        if ($profile === null) {
            return self::FAILURE;
        }

        try {
            if ($this->option('sales') !== null) {
                $designation = (string) $this->option('designation');
                if ($designation === 'auto') {
                    $designation = $profile->toDomain()->vatStatus->settlesVat() ? '0.23' : 'zw';
                }

                $recorder->recordSales(
                    $profile,
                    $period,
                    [$designation => Money::parse((string) $this->option('sales'))],
                    correctionReason: $this->option('reason') !== null ? (string) $this->option('reason') : null,
                );
            }

            $report = $recorder->settle($profile, $period);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->output->write((new TextReportRenderer())->render($report, ! $this->option('brief')));

        return self::SUCCESS;
    }

    private function resolveProfile(): ?TaxProfileModel
    {
        if ($this->option('profile') !== null) {
            $profile = TaxProfileModel::find((int) $this->option('profile'));
            if ($profile === null) {
                $this->error('Nie znaleziono profilu podatnika o ID '.$this->option('profile').'.');
            }

            return $profile;
        }

        $profiles = TaxProfileModel::query()->limit(2)->get();

        if ($profiles->isEmpty()) {
            $this->error('Brak profilu podatnika. Utwórz go przed rozliczeniem.');

            return null;
        }

        if ($profiles->count() > 1) {
            // Picking one would settle the wrong taxpayer's month.
            $this->error('Istnieje więcej niż jeden profil podatnika — wskaż go przez --profile=ID.');

            return null;
        }

        return $profiles->first();
    }
}
