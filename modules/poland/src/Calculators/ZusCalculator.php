<?php

declare(strict_types=1);

namespace Poland\Calculators;

use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;
use Poland\Rates\RateRepository;
use Poland\Reporting\Breakdown;

/**
 * ZUS contributions for one month.
 *
 * Two things here are easy to get wrong and are therefore handled explicitly
 * rather than by convention:
 *
 * 1. THE HEALTH CONTRIBUTION YEAR IS NOT THE CALENDAR YEAR. It runs 1 February
 *    to 31 January, so January is settled on the previous year's figures. The
 *    rate table is dated by month and the lookup uses the settled month, which
 *    makes January come out right without anybody remembering the rule.
 *
 * 2. THE HEALTH CONTRIBUTION LOOKS BACK. Under the tax scale and the flat tax
 *    it is 9% / 4.9% of the income of the month BEFORE the one being settled
 *    (art. 81 ust. 2). Feeding it the current month's income is a silent
 *    off-by-one that nothing downstream can detect, so the caller must pass the
 *    previous month's income and this class states which month it used.
 */
final class ZusCalculator
{
    public function __construct(private readonly RateRepository $rates) {}

    /**
     * @param Money|null $previousMonthIncome Income of the month before $period,
     *        required under the tax scale and the flat tax.
     * @param Money|null $revenueYearToDate Revenue accumulated in the calendar
     *        year, which picks the ryczałt health band.
     */
    public function calculate(
        TaxProfile $profile,
        Period $period,
        ?Money $previousMonthIncome = null,
        ?Money $revenueYearToDate = null,
        ?Money $socialContributionsPaidYearToDate = null,
    ): ZusSettlement {
        $breakdown = new Breakdown('ZUS — '.$period->label());
        $notes = [];

        $scheme = $profile->zusScheme;
        $breakdown->addText('Schemat składek', $scheme->label());

        if ($profile->zusSchemeExpiredAt($period)) {
            $notes[] = sprintf(
                'SCHEMAT WYGASŁ: "%s" przysługuje przez %d mies., a działalność trwa już %d mies. '
                .'(od %s). Raport liczy dalej według ustawionego schematu — zmiana schematu jest '
                .'decyzją podatnika i nie zostanie wprowadzona automatycznie.',
                $scheme->label(),
                (int) $scheme->maxMonths(),
                $profile->monthsInBusinessAt($period),
                $profile->businessStartedAt->toString(),
            );
        }

        [$base, $components, $socialTotal] = $this->social($profile, $period, $breakdown, $notes);
        [$health, $healthBasis] = $this->health(
            $profile, $period, $previousMonthIncome, $revenueYearToDate,
            $socialContributionsPaidYearToDate, $breakdown, $notes,
        );

        $total = $socialTotal->plus($health);
        $breakdown->addTotal(
            'RAZEM ZUS do zapłaty',
            $total,
            sprintf('społeczne %s + zdrowotna %s', $socialTotal->format(''), $health->format('')),
            'Termin: do 20. dnia miesiąca następnego',
        );

        return new ZusSettlement(
            $period, $base, $components, $socialTotal, $health, $total, $breakdown, $notes, $healthBasis,
        );
    }

    /**
     * @param list<string> $notes
     * @return array{0: Money, 1: array<string,Money>, 2: Money}
     */
    private function social(TaxProfile $profile, Period $period, Breakdown $breakdown, array &$notes): array
    {
        $table = $this->rates->zusSocial();
        $version = $table->for($period);

        if (! $profile->zusScheme->paysSocial()) {
            $breakdown->add(
                'Składki społeczne',
                Money::zero(),
                'ulga na start — brak składek społecznych przez pierwsze 6 miesięcy',
                'Prawo przedsiębiorców, art. 18',
                $version->stamp(),
            );

            return [Money::zero(), [], Money::zero()];
        }

        $base = match ($profile->zusScheme) {
            ZusScheme::Preferential => $version->money('preferential_base'),
            ZusScheme::MalyZusPlus => $this->malyZusPlusBase($profile, $version, $notes),
            default => $version->money('full_base'),
        };

        // An incomplete first month reduces the SOCIAL base proportionally by
        // calendar days (art. 18 ust. 9-10). The health contribution is
        // indivisible and is deliberately not touched here.
        $fullMonthBase = $base;
        $insuredDays = $profile->insuredDaysIn($period);
        if ($insuredDays !== null) {
            $daysInMonth = (int) $period->lastDay()->format('j');
            $base = $base->times($insuredDays / $daysInMonth);
            $notes[] = sprintf(
                'Niepełny miesiąc: działalność od %d. dnia, ubezpieczenie przez %d z %d dni. '
                .'Podstawa składek społecznych zmniejszona proporcjonalnie; składka zdrowotna '
                .'jest niepodzielna i płatna w pełnej wysokości.',
                $profile->businessStartedOnDay, $insuredDays, $daysInMonth,
            );
            $breakdown->add(
                'Podstawa za pełny miesiąc',
                $fullMonthBase,
                null,
                'Ustawa o systemie ubezpieczeń społecznych, art. 18 ust. 9',
            );
        }

        $breakdown->add(
            $insuredDays !== null ? 'Podstawa po zmniejszeniu za niepełny miesiąc' : 'Podstawa wymiaru składek społecznych',
            $base,
            match ($profile->zusScheme) {
                ZusScheme::Preferential => '30% minimalnego wynagrodzenia ('.$version->money('minimum_wage')->format('').')',
                ZusScheme::MalyZusPlus => 'ustalona indywidualnie z dochodu za rok poprzedni',
                default => '60% prognozowanego przeciętnego wynagrodzenia ('.$version->money('forecast_average_wage')->format('').')',
            },
            'Ustawa o systemie ubezpieczeń społecznych, art. 18',
            $version->stamp(),
        );

        $rates = (array) $table->constant('component_rates');
        $accidentRate = $profile->accidentRate ?? (float) $rates['accident'];

        $components = [
            'emerytalne' => $base->times((float) $rates['pension']),
            'rentowe' => $base->times((float) $rates['disability']),
        ];

        if ($profile->sicknessInsurance) {
            $components['chorobowe'] = $base->times((float) $rates['sickness']);
        }

        $components['wypadkowe'] = $base->times($accidentRate);

        // Fundusz Pracy is due only once the base reaches the minimum wage,
        // which is exactly why the preferential scheme does not pay it.
        $minimumWage = $version->money('minimum_wage');
        $labourFundApplies = (bool) $table->constant('labour_fund_requires_base_at_least_minimum_wage')
            ? ! $fullMonthBase->lessThan($minimumWage)
            : true;

        if ($labourFundApplies) {
            $components['Fundusz Pracy i FS'] = $base->times((float) $rates['labour_fund']);
        }

        $labels = [
            'emerytalne' => ['Ubezpieczenie emerytalne', $rates['pension']],
            'rentowe' => ['Ubezpieczenie rentowe', $rates['disability']],
            'chorobowe' => ['Ubezpieczenie chorobowe (dobrowolne)', $rates['sickness']],
            'wypadkowe' => ['Ubezpieczenie wypadkowe', $accidentRate],
            'Fundusz Pracy i FS' => ['Fundusz Pracy i Fundusz Solidarnościowy', $rates['labour_fund']],
        ];

        foreach ($components as $key => $amount) {
            [$label, $rate] = $labels[$key];
            $breakdown->add(
                $label,
                $amount,
                sprintf('%s × %s%%', $base->format(''), rtrim(rtrim(number_format((float) $rate * 100, 2, ',', ''), '0'), ',')),
            );
        }

        if (! $labourFundApplies) {
            $breakdown->addText(
                'Fundusz Pracy i FS',
                'nie występuje — podstawa niższa niż minimalne wynagrodzenie',
                'Ustawa o promocji zatrudnienia, art. 104b',
            );
        }

        if (! $profile->sicknessInsurance) {
            $breakdown->addText('Ubezpieczenie chorobowe', 'zrezygnowano (dobrowolne)');
        }

        $total = Money::sum(array_values($components));
        $breakdown->addTotal('Składki społeczne razem', $total);

        return [$base, $components, $total];
    }

    /** @param list<string> $notes */
    private function malyZusPlusBase(TaxProfile $profile, \Poland\Rates\RateVersion $version, array &$notes): Money
    {
        $base = $profile->malyZusPlusBase;
        if ($base === null) {
            // TaxProfile validation guarantees this, but the guard documents the contract.
            throw new \LogicException('Mały ZUS Plus base missing.');
        }

        $min = $version->money('maly_zus_plus_min_base');
        $max = $version->money('maly_zus_plus_max_base');

        if ($base->lessThan($min)) {
            $notes[] = sprintf(
                'Podstawa Mały ZUS Plus (%s) była niższa od dolnej granicy %s i została do niej podniesiona.',
                $base->format(), $min->format(),
            );

            return $min;
        }

        if ($base->greaterThan($max)) {
            $notes[] = sprintf(
                'Podstawa Mały ZUS Plus (%s) przekraczała górną granicę %s i została do niej obniżona.',
                $base->format(), $max->format(),
            );

            return $max;
        }

        return $base;
    }

    /**
     * @param list<string> $notes
     * @return array{0: Money, 1: string}
     */
    private function health(
        TaxProfile $profile,
        Period $period,
        ?Money $previousMonthIncome,
        ?Money $revenueYearToDate,
        ?Money $socialContributionsPaidYearToDate,
        Breakdown $breakdown,
        array &$notes,
    ): array {
        $version = $this->rates->zusHealth()->for($period);

        $breakdown->addText(
            'Rok składkowy (zdrowotna)',
            (string) $version->get('contribution_year')
            .' — uwaga: rok składkowy trwa od 1 lutego do 31 stycznia',
            'Ustawa o świadczeniach opieki zdrowotnej, art. 81 ust. 2',
        );

        if ($profile->pitRegime === PitRegime::LumpSum) {
            return $this->lumpSumHealth(
                $profile, $period, $revenueYearToDate, $socialContributionsPaidYearToDate,
                $version, $breakdown, $notes,
            );
        }

        $minimum = $version->money('minimum_monthly');
        $rate = $profile->pitRegime === PitRegime::Flat
            ? $version->rate('flat_rate')
            : $version->rate('scale_rate');

        if ($previousMonthIncome === null) {
            $notes[] = sprintf(
                'DOCHÓD ZA MIESIĄC POPRZEDNI NIEZNANY: składka zdrowotna dla %s liczona jest od '
                .'dochodu miesiąca poprzedzającego (art. 81 ust. 2). Nie podano go, więc przyjęto '
                .'składkę MINIMALNĄ %s — jest to dolna granica, a nie wyliczenie.',
                $profile->pitRegime->label(),
                $minimum->format(),
            );

            $breakdown->add(
                'Składka zdrowotna (minimalna)',
                $minimum,
                sprintf('9%% × %s (minimalne wynagrodzenie)', $version->money('minimum_base')->format('')),
                'Ustawa o świadczeniach opieki zdrowotnej, art. 79a',
                $version->stamp(),
            );

            return [$minimum, 'minimum — brak dochodu za miesiąc poprzedni'];
        }

        $incomeBase = $previousMonthIncome->clampAtZero();
        $computed = $incomeBase->times($rate);
        $health = Money::max($computed, $minimum);

        $breakdown->add(
            'Dochód za miesiąc poprzedni ('.$period->previous()->toString().')',
            $previousMonthIncome,
            null,
            'Ustawa o świadczeniach opieki zdrowotnej, art. 81 ust. 2',
        );
        $breakdown->add(
            'Składka zdrowotna wyliczona',
            $computed,
            sprintf('%s × %s%%', $incomeBase->format(''), rtrim(rtrim(number_format($rate * 100, 2, ',', ''), '0'), ',')),
        );

        if ($health->equals($minimum) && ! $computed->equals($minimum)) {
            $breakdown->add(
                'Składka zdrowotna po zastosowaniu minimum',
                $minimum,
                sprintf('9%% × %s (minimalne wynagrodzenie)', $version->money('minimum_base')->format('')),
                'Ustawa o świadczeniach opieki zdrowotnej, art. 79a',
                $version->stamp(),
            );
        }

        return [$health, sprintf(
            '%s%% dochodu z %s, nie mniej niż %s',
            rtrim(rtrim(number_format($rate * 100, 2, ',', ''), '0'), ','),
            $period->previous()->toString(),
            $minimum->format(),
        )];
    }

    /**
     * @param list<string> $notes
     * @return array{0: Money, 1: string}
     */
    private function lumpSumHealth(
        TaxProfile $profile,
        Period $period,
        ?Money $revenueYearToDate,
        ?Money $socialContributionsPaidYearToDate,
        \Poland\Rates\RateVersion $version,
        Breakdown $breakdown,
        array &$notes,
    ): array {
        $bands = (array) $version->get('lump_sum_bands');

        $reference = $profile->healthBandFromPreviousYear
            ? $profile->previousYearRevenue
            : $revenueYearToDate;

        // The band is read from revenue accumulated since 1 January, which the
        // taxpayer MAY reduce by the social contributions they have paid
        // (art. 81 ust. 2g). Whether that election was made changes which band
        // applies near a threshold, so it is stated rather than assumed.
        if ($reference !== null && $profile->reduceHealthBandBySocial && $socialContributionsPaidYearToDate !== null) {
            $breakdown->add(
                'Odliczenie od przychodu dla ustalenia progu: składki społeczne zapłacone',
                $socialContributionsPaidYearToDate,
                null,
                'Ustawa o świadczeniach opieki zdrowotnej, art. 81 ust. 2g',
            );
            $reference = $reference->minus($socialContributionsPaidYearToDate)->clampAtZero();
        }

        if ($reference === null) {
            // The lowest band is the floor, so saying so is honest; presenting it
            // as "the" contribution would not be.
            $lowest = Money::parse((string) $bands[0]['monthly']);
            $notes[] = sprintf(
                'PRZYCHÓD NARASTAJĄCO NIEZNANY: próg składki zdrowotnej na ryczałcie zależy od '
                .'przychodu od początku roku. Przyjęto najniższy próg %s — jest to dolna granica, '
                .'a nie wyliczenie.',
                $lowest->format(),
            );
            $breakdown->add('Składka zdrowotna (najniższy próg)', $lowest, null, null, $version->stamp());

            return [$lowest, 'najniższy próg — brak przychodu narastająco'];
        }

        foreach ($bands as $index => $band) {
            $ceiling = $band['revenue_up_to'] !== null ? Money::parse((string) $band['revenue_up_to']) : null;
            if ($ceiling === null || ! $reference->greaterThan($ceiling)) {
                $amount = Money::parse((string) $band['monthly']);

                $bandLabel = $ceiling === null
                    ? sprintf('powyżej %s', Money::parse((string) $bands[count($bands) - 2]['revenue_up_to'])->format())
                    : sprintf('do %s', $ceiling->format());

                $breakdown->add(
                    $profile->healthBandFromPreviousYear
                        ? 'Przychód za rok poprzedni (podstawa progu)'
                        : 'Przychód od początku roku (podstawa progu)',
                    $reference,
                    null,
                    'Ustawa o świadczeniach opieki zdrowotnej, art. 81 ust. 2e',
                );
                $breakdown->add(
                    'Składka zdrowotna — próg '.$bandLabel,
                    $amount,
                    sprintf(
                        '9%% × %d%% × %s (przeciętne wynagrodzenie w IV kw.)',
                        (int) $band['base_percent_of_reference'],
                        Money::parse((string) $version->get('lump_sum_reference_wage'))->format(''),
                    ),
                    'Ustawa o świadczeniach opieki zdrowotnej, art. 81 ust. 2e',
                    $version->stamp(),
                );

                // Crossing a band mid-year raises every remaining month, so warn early.
                if ($ceiling !== null) {
                    $headroom = $ceiling->minus($reference);
                    $next = Money::parse((string) $bands[$index + 1]['monthly']);
                    if ($headroom->lessThan($ceiling->times(0.15))) {
                        $notes[] = sprintf(
                            'Do następnego progu składki zdrowotnej zostało %s przychodu. '
                            .'Po przekroczeniu składka rośnie z %s do %s miesięcznie.',
                            $headroom->format(), $amount->format(), $next->format(),
                        );
                    }
                }

                if ($index > 0) {
                    $notes[] = sprintf(
                        'Próg składki zdrowotnej podniesiony w trakcie roku. Wyższa składka (%s) '
                        .'obowiązuje za ten miesiąc i każdy kolejny do końca roku, a po zakończeniu '
                        .'roku należy dopłacić wyrównanie za miesiące rozliczone niższą składką.',
                        $amount->format(),
                    );
                }

                return [$amount, 'próg ryczałtowy '.$bandLabel];
            }
        }

        throw new \LogicException('Health contribution bands must end with an open-ended band.');
    }
}
