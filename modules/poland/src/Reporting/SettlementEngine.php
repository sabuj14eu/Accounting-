<?php

declare(strict_types=1);

namespace Poland\Reporting;

use Poland\Calculators\PitCalculator;
use Poland\Calculators\PitInput;
use Poland\Calculators\PitSettlement;
use Poland\Calculators\VatCalculator;
use Poland\Calculators\ZusCalculator;
use Poland\Calculators\ZusSettlement;
use Poland\Domain\Enums\ContributionDeductionBasis;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Ledger;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;
use Poland\Rates\RateRepository;
use Poland\Support\DeadlineCalendar;

/**
 * Settles a month by replaying the year that leads up to it.
 *
 * Nothing here settles a month in isolation, because in Polish law almost
 * nothing about a month IS local to it: the PIT advance is cumulative from
 * 1 January, the ryczałt health band is chosen by revenue accumulated since
 * 1 January, and the health contribution under the scale and the flat tax
 * looks back at the previous month's income. So the engine walks January
 * forward and hands each month what the months before it produced.
 */
final class SettlementEngine
{
    private readonly ZusCalculator $zus;

    private readonly VatCalculator $vat;

    private readonly PitCalculator $pit;

    private readonly DeadlineCalendar $deadlines;

    /**
     * @param bool $requireOfficialRates When true, a settlement using a rate
     *        table that has not been verified against its official source is
     *        REFUSED rather than produced with a warning. Production turns this
     *        on; it is the mechanism that stops a figure sourced from the trade
     *        press being handed to somebody as an amount to pay.
     */
    public function __construct(
        private readonly RateRepository $rates,
        private readonly bool $requireOfficialRates = false,
    ) {
        $this->zus = new ZusCalculator($rates);
        $this->vat = new VatCalculator($rates);
        $this->pit = new PitCalculator($rates);
        $this->deadlines = new DeadlineCalendar($rates);
    }

    public static function withDefaultRates(bool $requireOfficialRates = false): self
    {
        return new self(RateRepository::default(), $requireOfficialRates);
    }

    public function requiresOfficialRates(): bool
    {
        return $this->requireOfficialRates;
    }

    public function settle(TaxProfile $profile, Ledger $ledger, Period $period): MonthlyTaxReport
    {
        $coverage = $this->rates->coverage($period);
        if (! $coverage['ok']) {
            throw \Poland\Rates\MissingRateException::for(implode(', ', $coverage['missing']), $period);
        }

        $unverified = $this->unverifiedTables($period);
        if ($this->requireOfficialRates && $unverified !== []) {
            throw \Poland\Rates\UnverifiedRateException::for($period, $unverified);
        }

        $sales = $ledger->salesFor($period);
        if ($sales === null) {
            throw new \InvalidArgumentException(sprintf(
                'No sales recorded for %s. Record the fiscal cash-register report for that month '
                .'before settling it — an unrecorded month is not a month of zero sales.',
                $period->toString(),
            ));
        }

        $warnings = [];
        $notes = [];

        $missing = $ledger->missingMonths($period);
        if ($missing !== []) {
            $warnings[] = sprintf(
                'BRAK DANYCH ZA MIESIĄCE: %s. Zaliczka na PIT liczona jest narastająco od 1 stycznia, '
                .'więc brakujące miesiące zaniżają zarówno podatek narastająco, jak i zaliczki już '
                .'zapłacone. Wynik jest niepełny, a nie zerowy.',
                implode(', ', array_map(static fn (Period $p): string => $p->toString(), $missing)),
            );
        }

        $run = $this->replayYear($profile, $ledger, $period);
        $current = $run[$period->toString()];

        /** @var ZusSettlement $zus */
        $zus = $current['zus'];
        /** @var PitSettlement $pit */
        $pit = $current['pit'];

        $vat = $this->vat->calculate(
            $profile,
            $period,
            $sales,
            $ledger->purchasesFor($period),
            null,
            $current['turnover_ytd'],
        );

        $totalDue = $zus->total->plus($vat->amountToPay)->plus($pit->advanceDue);

        $deadlines = [
            'zus' => $this->deadlines->for('zus', $period),
            'pit_advance' => $this->deadlines->for('pit_advance', $period),
        ];
        if ($vat->settlesVat) {
            $deadlines['vat'] = $this->deadlines->for('vat', $period);
        }

        foreach ($deadlines as $deadline) {
            if (! $deadline['verified']) {
                $warnings[] = sprintf(
                    'Kalendarz świąt nie obejmuje %s — termin "%s" podano jako datę ustawową, '
                    .'bez przesunięcia na dzień roboczy. Sprawdź termin ręcznie.',
                    $deadline['statutory']->format('Y'),
                    $deadline['label'],
                );
            }
        }

        $notes[] = 'Podstawa odliczenia składek: '.$profile->deductionBasis->label().'.';

        if ($unverified !== []) {
            $warnings[] = sprintf(
                'STAWKI NIEZWERYFIKOWANE URZĘDOWO (%s). Wartości pochodzą ze źródeł wtórnych i '
                .'zostały skontrolowane arytmetycznie, ale nie potwierdzono ich w publikacji organu. '
                .'Ten wynik nadaje się do orientacji, NIE do złożenia. Lista do sprawdzenia: '
                .'php artisan poland:rate-provenance --todo',
                implode(', ', array_keys($unverified)),
            );
        }

        if ($profile->pitRegime !== PitRegime::LumpSum && ! $ledger->hasAnyPurchases()) {
            $warnings[] = 'Wybrana forma opodatkowania rozlicza DOCHÓD, a w ewidencji nie ma żadnych '
                .'kosztów. Wynik PIT jest górną granicą, nie kwotą do zapłaty.';
        }

        $isEstimate = $pit->isEstimate
            || ($vat->settlesVat && $ledger->purchasesFor($period) === null);

        return new MonthlyTaxReport(
            $profile,
            $period,
            $sales->grossTotal(),
            $current['revenue'],
            $zus,
            $vat,
            $pit,
            $totalDue,
            $deadlines,
            array_merge($notes, $zus->notes, $pit->notes, $vat->notes),
            $warnings,
            $isEstimate,
            $this->rateSources($period),
            $this->rateProvenance($period),
            $unverified === [],
        );
    }

    /**
     * Settle every month of the year up to and including $through.
     *
     * @return array<string,array{revenue: Money, costs: Money, income: Money, zus: ZusSettlement, pit: PitSettlement, turnover_ytd: Money}>
     */
    public function replayYear(TaxProfile $profile, Ledger $ledger, Period $through): array
    {
        $result = [];

        $revenueYtd = Money::zero();
        $costsYtd = Money::zero();
        $turnoverYtd = Money::zero();
        $advancesYtd = Money::zero();

        /** @var list<ZusSettlement> $settledZus indexed by month number - 1 */
        $settledZus = [];

        foreach ($through->yearToDate() as $month) {
            $sales = $ledger->salesFor($month);
            $purchases = $ledger->purchasesFor($month);

            $revenue = $sales?->revenueForIncomeTax($profile->vatStatus) ?? Money::zero();
            $costs = $purchases?->deductibleCostsNet ?? Money::zero();

            $revenueYtd = $revenueYtd->plus($revenue);
            $costsYtd = $costsYtd->plus($costs);
            $turnoverYtd = $turnoverYtd->plus($sales?->turnoverForExemptionLimit() ?? Money::zero());

            // Social contributions paid so far this year — needed before the
            // health contribution, because the ryczałt band may be read from
            // revenue net of them.
            $socialPaidSoFar = Money::sum(array_map(
                static fn (ZusSettlement $s): Money => $s->socialTotal,
                $settledZus,
            ));

            $zus = $this->zus->calculate(
                $profile,
                $month,
                $this->previousMonthIncome($profile, $ledger, $month, $settledZus),
                $revenueYtd,
                $socialPaidSoFar,
            );
            $settledZus[] = $zus;

            [$socialDeductible, $healthPaid] = $this->deductibleContributions(
                $profile, $ledger, $month, $settledZus,
            );

            $pitInput = new PitInput(
                $revenueYtd,
                $costsYtd,
                $socialDeductible,
                $healthPaid,
                $advancesYtd,
                $profile->pitRegime === PitRegime::LumpSum
                    ? $this->lumpSumStreams($profile, $ledger, $through, $month)
                    : [],
                $ledger->hasAnyPurchases(),
            );

            $pit = $this->pit->calculate($profile, $month, $pitInput);
            $advancesYtd = $advancesYtd->plus($pit->advanceDue);

            $result[$month->toString()] = [
                'revenue' => $revenue,
                'costs' => $costs,
                'income' => $revenue->minus($costs),
                'zus' => $zus,
                'pit' => $pit,
                'turnover_ytd' => $turnoverYtd,
            ];
        }

        return $result;
    }

    /**
     * Income of the month before $month, which is the base of the health
     * contribution under the scale and the flat tax (art. 81 ust. 2).
     *
     * Returns null — not zero — when that month is not in the ledger, so the
     * ZUS calculator falls back to the statutory minimum and says it did.
     *
     * @param list<ZusSettlement> $settledZus
     */
    private function previousMonthIncome(
        TaxProfile $profile,
        Ledger $ledger,
        Period $month,
        array $settledZus,
    ): ?Money {
        if ($profile->pitRegime === PitRegime::LumpSum) {
            return null; // Not used: the ryczałt band is revenue-based.
        }

        $previous = $month->previous();
        $sales = $ledger->salesFor($previous);
        if ($sales === null) {
            return null;
        }

        $revenue = $sales->revenueForIncomeTax($profile->vatStatus);
        $costs = $ledger->purchasesFor($previous)?->deductibleCostsNet ?? Money::zero();

        // Income for health purposes is net of social contributions paid, when
        // they were not already taken as a cost.
        $previousSocial = $previous->year === $month->year && isset($settledZus[$previous->month - 1])
            ? $settledZus[$previous->month - 1]->socialTotal
            : ($ledger->openingContributionsFor($previous)['social'] ?? Money::zero());

        return $revenue->minus($costs)->minus($previousSocial)->clampAtZero();
    }

    /**
     * Which contributions reduce this month's PIT, under the profile's basis.
     *
     * @param list<ZusSettlement> $settledZus settlements for January..$month
     * @return array{0: Money, 1: Money} social deductible YTD, health paid YTD
     */
    private function deductibleContributions(
        TaxProfile $profile,
        Ledger $ledger,
        Period $month,
        array $settledZus,
    ): array {
        if ($profile->deductionBasis === ContributionDeductionBasis::AccruedForMonth) {
            return [
                Money::sum(array_map(static fn (ZusSettlement $s): Money => $s->socialTotal, $settledZus)),
                Money::sum(array_map(static fn (ZusSettlement $s): Money => $s->health, $settledZus)),
            ];
        }

        // Cash basis: the contributions PAID during January..$month are the ones
        // accrued for December..$month-1. December comes from the opening
        // balance, and if it is absent the caller is told rather than charged
        // an invented deduction of zero.
        $previousMonths = array_slice($settledZus, 0, max(0, count($settledZus) - 1));
        $social = Money::sum(array_map(static fn (ZusSettlement $s): Money => $s->socialTotal, $previousMonths));
        $health = Money::sum(array_map(static fn (ZusSettlement $s): Money => $s->health, $previousMonths));

        $opening = $ledger->openingContributionsFor(Period::of($month->year - 1, 12));
        if ($opening !== null) {
            $social = $social->plus($opening['social']);
            $health = $health->plus($opening['health']);
        }

        return [$social, $health];
    }

    /**
     * Revenue split by ryczałt rate, accumulated to $month.
     *
     * @return array<string,Money>
     */
    private function lumpSumStreams(TaxProfile $profile, Ledger $ledger, Period $through, Period $month): array
    {
        $streams = [];
        foreach ($month->yearToDate() as $m) {
            $sales = $ledger->salesFor($m);
            if ($sales === null) {
                continue;
            }
            foreach ($sales->revenueByLumpSumRate($profile->vatStatus, (float) $profile->lumpSumRate) as $rate => $amount) {
                $streams[$rate] = isset($streams[$rate]) ? $streams[$rate]->plus($amount) : $amount;
            }
        }

        return $streams;
    }

    /** @return array<string,string> */
    private function rateSources(Period $period): array
    {
        $sources = [];
        foreach ($this->versionsFor($period) as $name => $version) {
            $sources[$name] = $version->stamp();
        }

        return $sources;
    }

    /**
     * The full provenance record of every rate version this settlement used.
     *
     * Stored with the settlement so it stays reproducible: rates change, and a
     * settlement must remain defensible as it stood on the day it was made.
     *
     * @return array<string,array<string,mixed>>
     */
    private function rateProvenance(Period $period): array
    {
        $provenance = [];
        foreach ($this->versionsFor($period) as $name => $version) {
            $provenance[$name] = $version->describe();
        }

        return $provenance;
    }

    /**
     * Tables whose version for this period is not verified against an official
     * source.
     *
     * @return array<string,\Poland\Rates\RateProvenance>
     */
    public function unverifiedTables(Period $period): array
    {
        $offending = [];
        foreach ($this->versionsFor($period) as $name => $version) {
            if (! $version->isFitForFiling()) {
                $offending[$name] = $version->provenance;
            }
        }

        return $offending;
    }

    /** @return array<string,\Poland\Rates\RateVersion> */
    private function versionsFor(Period $period): array
    {
        $versions = [];
        foreach (RateRepository::TABLES as $name) {
            $table = $this->rates->table($name);
            if ($table->versions() === []) {
                continue;
            }
            $versions[$name] = $table->for($period);
        }

        return $versions;
    }
}
