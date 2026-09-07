<?php

declare(strict_types=1);

namespace Poland\Calculators;

use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;
use Poland\Rates\RateRepository;
use Poland\Reporting\Breakdown;

/**
 * The monthly PIT advance (zaliczka), under all three JDG regimes.
 *
 * Advances are cumulative by law (ustawa o PIT, art. 44 ust. 3): tax is
 * computed on everything earned since 1 January and the advances already due
 * are subtracted. Computing a month in isolation drifts, because the tax-free
 * allowance, the 32% threshold and the ryczałt deductions are all annual.
 */
final class PitCalculator
{
    public function __construct(private readonly RateRepository $rates) {}

    public function calculate(TaxProfile $profile, Period $period, PitInput $input): PitSettlement
    {
        return match ($profile->pitRegime) {
            PitRegime::LumpSum => $this->lumpSum($profile, $period, $input),
            PitRegime::Flat => $this->flat($profile, $period, $input),
            PitRegime::Scale => $this->scale($profile, $period, $input),
        };
    }

    /**
     * Ryczałt: a rate on REVENUE. Costs never enter, which is what makes a
     * cash-register-only workflow exact under this regime and only an estimate
     * under the other two.
     */
    private function lumpSum(TaxProfile $profile, Period $period, PitInput $input): PitSettlement
    {
        $version = $this->rates->pit()->for($period);
        $breakdown = new Breakdown('Ryczałt od przychodów ewidencjonowanych — '.$period->label());
        $notes = [];

        $breakdown->addText('Forma opodatkowania', $profile->pitRegime->label());
        $breakdown->add('Przychód od początku roku', $input->revenueYearToDate);

        // Deductions: social contributions in full, plus half of the health
        // contributions actually paid (art. 11 ust. 1a ustawy o ryczałcie).
        $social = $input->socialContributionsDeductibleYearToDate;
        $halfHealth = $input->healthContributionsPaidYearToDate->dividedBy(2.0);
        $deductions = $social->plus($halfHealth);

        $breakdown->add(
            'Odliczenie: składki społeczne zapłacone',
            $social,
            null,
            'Ustawa o ryczałcie, art. 11 ust. 1 w zw. z art. 26 ust. 1 pkt 2 ustawy o PIT',
        );
        $breakdown->add(
            'Odliczenie: 50% składki zdrowotnej zapłaconej',
            $halfHealth,
            sprintf('50%% × %s', $input->healthContributionsPaidYearToDate->format('')),
            'Ustawa o ryczałcie, art. 11 ust. 1a',
        );

        $baseBeforeRounding = $input->revenueYearToDate->minus($deductions)->clampAtZero();
        $base = $baseBeforeRounding->roundedToZloty();

        $breakdown->add(
            'Podstawa opodatkowania (narastająco)',
            $base,
            sprintf('%s − %s, zaokrąglone do pełnych złotych', $input->revenueYearToDate->format(''), $deductions->format('')),
            'Ordynacja podatkowa, art. 63 § 1',
        );

        // Multiple ryczałt rates: deductions reduce each revenue stream in
        // proportion to its share of total revenue (art. 11 ust. 3).
        $streams = $input->revenueByLumpSumRate !== []
            ? $input->revenueByLumpSumRate
            : [(string) $profile->lumpSumRate => $input->revenueYearToDate];

        $taxYtd = Money::zero();
        $totalRevenue = Money::sum(array_values($streams));

        foreach ($streams as $rateKey => $streamRevenue) {
            $rate = (float) $rateKey;
            $share = $totalRevenue->isZero()
                ? Money::zero()
                : $deductions->times($streamRevenue->grosze / $totalRevenue->grosze);
            $streamBase = $streamRevenue->minus($share)->clampAtZero()->roundedToZloty();
            $streamTax = $streamBase->times($rate);
            $taxYtd = $taxYtd->plus($streamTax);

            $breakdown->add(
                sprintf('Ryczałt %s%% od %s', rtrim(rtrim(number_format($rate * 100, 2, ',', ''), '0'), ','), $streamBase->format('')),
                $streamTax,
                count($streams) > 1
                    ? sprintf('odliczenia rozdzielone proporcjonalnie do przychodu (%s z %s)', $streamRevenue->format(''), $totalRevenue->format(''))
                    : null,
                'Ustawa o ryczałcie, art. 12 i art. 11 ust. 3',
            );
        }

        $taxYtd = $taxYtd->roundedToZloty();
        $breakdown->add('Ryczałt należny od początku roku', $taxYtd, null, 'Ordynacja podatkowa, art. 63 § 1');
        $breakdown->add('Ryczałt zapłacony za wcześniejsze miesiące', $input->advancesAlreadyDue);

        $due = $taxYtd->minus($input->advancesAlreadyDue)->clampAtZero();
        $breakdown->addTotal(
            'Ryczałt do zapłaty za '.$period->label(),
            $due,
            sprintf('%s − %s', $taxYtd->format(''), $input->advancesAlreadyDue->format('')),
            'Termin: do 20. dnia miesiąca następnego',
        );

        if ($taxYtd->lessThan($input->advancesAlreadyDue)) {
            $notes[] = sprintf(
                'Ryczałt narastająco (%s) jest niższy niż suma zapłacona za wcześniejsze miesiące (%s). '
                .'Za ten miesiąc nie ma dopłaty; nadpłata rozliczy się w kolejnych miesiącach lub w PIT-28.',
                $taxYtd->format(), $input->advancesAlreadyDue->format(),
            );
        }

        return new PitSettlement(
            $period, PitRegime::LumpSum, $input->revenueYearToDate, Money::zero(), $deductions,
            $base, $taxYtd, $input->advancesAlreadyDue, $due, $breakdown, $notes, false,
        );
    }

    private function flat(TaxProfile $profile, Period $period, PitInput $input): PitSettlement
    {
        $version = $this->rates->pit()->for($period);
        $healthVersion = $this->rates->zusHealth()->for($period);
        $breakdown = new Breakdown('Podatek liniowy — '.$period->label());
        $notes = [];

        $breakdown->addText('Forma opodatkowania', $profile->pitRegime->label());
        $breakdown->add('Przychód od początku roku', $input->revenueYearToDate);
        $breakdown->add('Koszty uzyskania przychodu', $input->costsYearToDate);

        $income = $input->revenueYearToDate->minus($input->costsYearToDate);
        $breakdown->add('Dochód', $income, sprintf('%s − %s', $input->revenueYearToDate->format(''), $input->costsYearToDate->format('')));

        $social = $input->socialContributionsDeductibleYearToDate;
        $breakdown->add('Odliczenie: składki społeczne', $social, null, 'Ustawa o PIT, art. 26 ust. 1 pkt 2');

        // Health contributions are deductible under the flat tax up to an
        // annual cap; the cap is a rate-table value, not a constant in code.
        $healthLimit = $healthVersion->money('flat_tax_deduction_limit_annual');
        $healthDeductible = Money::min($input->healthContributionsPaidYearToDate, $healthLimit);
        $breakdown->add(
            'Odliczenie: składka zdrowotna (limit '.$healthLimit->format().')',
            $healthDeductible,
            $input->healthContributionsPaidYearToDate->greaterThan($healthLimit)
                ? sprintf('zapłacono %s, odliczono do limitu', $input->healthContributionsPaidYearToDate->format(''))
                : null,
            'Ustawa o PIT, art. 30c ust. 2 pkt 2',
        );

        if ($input->lossCarriedForward->isPositive()) {
            $breakdown->add('Strata z lat ubiegłych', $input->lossCarriedForward, null, 'Ustawa o PIT, art. 9 ust. 3');
        }

        $base = $income
            ->minus($social)
            ->minus($healthDeductible)
            ->minus($input->lossCarriedForward)
            ->clampAtZero()
            ->roundedToZloty();

        $rate = $version->rate('flat.rate');
        $breakdown->add('Podstawa opodatkowania (narastająco)', $base, null, 'Ordynacja podatkowa, art. 63 § 1');

        $taxYtd = $base->times($rate)->roundedToZloty();
        $breakdown->add(
            'Podatek narastająco',
            $taxYtd,
            sprintf('%s × 19%%', $base->format('')),
            'Ustawa o PIT, art. 30c ust. 1',
        );
        $breakdown->add('Zaliczki za wcześniejsze miesiące', $input->advancesAlreadyDue);

        $due = $taxYtd->minus($input->advancesAlreadyDue)->clampAtZero();
        $breakdown->addTotal(
            'Zaliczka na PIT za '.$period->label(),
            $due,
            sprintf('%s − %s', $taxYtd->format(''), $input->advancesAlreadyDue->format('')),
            'Termin: do 20. dnia miesiąca następnego',
        );

        if (! $input->costsRecorded) {
            $notes[] = $this->missingCostsNote($profile);
        }

        return new PitSettlement(
            $period, PitRegime::Flat, $input->revenueYearToDate, $input->costsYearToDate,
            $social->plus($healthDeductible), $base, $taxYtd, $input->advancesAlreadyDue, $due,
            $breakdown, $notes, ! $input->costsRecorded,
        );
    }

    private function scale(TaxProfile $profile, Period $period, PitInput $input): PitSettlement
    {
        $version = $this->rates->pit()->for($period);
        $breakdown = new Breakdown('Skala podatkowa — '.$period->label());
        $notes = [];

        $breakdown->addText('Forma opodatkowania', $profile->pitRegime->label());
        $breakdown->add('Przychód od początku roku', $input->revenueYearToDate);
        $breakdown->add('Koszty uzyskania przychodu', $input->costsYearToDate);

        $income = $input->revenueYearToDate->minus($input->costsYearToDate);
        $breakdown->add('Dochód', $income);

        $social = $input->socialContributionsDeductibleYearToDate;
        $breakdown->add('Odliczenie: składki społeczne', $social, null, 'Ustawa o PIT, art. 26 ust. 1 pkt 2');
        $breakdown->addText(
            'Odliczenie: składka zdrowotna',
            'nie przysługuje na skali podatkowej',
            'Ustawa o PIT — odliczenie zniesione od 2022 r.',
        );

        if ($input->lossCarriedForward->isPositive()) {
            $breakdown->add('Strata z lat ubiegłych', $input->lossCarriedForward, null, 'Ustawa o PIT, art. 9 ust. 3');
        }

        $base = $income
            ->minus($social)
            ->minus($input->lossCarriedForward)
            ->clampAtZero()
            ->roundedToZloty();
        $breakdown->add('Podstawa opodatkowania (narastająco)', $base, null, 'Ordynacja podatkowa, art. 63 § 1');

        $threshold = $version->money('scale.first_threshold');
        $reducing = $version->money('scale.tax_reducing_amount');
        $firstRate = $version->rate('scale.first_rate');
        $secondRate = $version->rate('scale.second_rate');

        if ($base->greaterThan($threshold)) {
            $firstBandTax = $threshold->times($firstRate)->minus($reducing);
            $excess = $base->minus($threshold);
            $secondBandTax = $excess->times($secondRate);
            $taxYtd = $firstBandTax->plus($secondBandTax)->clampAtZero();

            $breakdown->add(
                'Podatek od pierwszego progu',
                $firstBandTax,
                sprintf('%s × 12%% − %s', $threshold->format(''), $reducing->format('')),
                'Ustawa o PIT, art. 27 ust. 1',
            );
            $breakdown->add(
                'Podatek od nadwyżki ponad '.$threshold->format(),
                $secondBandTax,
                sprintf('%s × 32%%', $excess->format('')),
                'Ustawa o PIT, art. 27 ust. 1',
            );
        } else {
            $taxYtd = $base->times($firstRate)->minus($reducing)->clampAtZero();
            $breakdown->add(
                'Podatek według skali',
                $taxYtd,
                sprintf('%s × 12%% − %s (kwota zmniejszająca)', $base->format(''), $reducing->format('')),
                'Ustawa o PIT, art. 27 ust. 1',
            );

            $allowance = $version->money('scale.tax_free_allowance');
            if (! $base->greaterThan($allowance)) {
                $breakdown->addText(
                    'Kwota wolna od podatku',
                    sprintf('podstawa %s mieści się w kwocie wolnej %s', $base->format(), $allowance->format()),
                    'Ustawa o PIT, art. 27 ust. 1',
                );
            }
        }

        $taxYtd = $taxYtd->roundedToZloty();
        $breakdown->add('Podatek narastająco', $taxYtd, null, 'Ordynacja podatkowa, art. 63 § 1');
        $breakdown->add('Zaliczki za wcześniejsze miesiące', $input->advancesAlreadyDue);

        $due = $taxYtd->minus($input->advancesAlreadyDue)->clampAtZero();
        $breakdown->addTotal(
            'Zaliczka na PIT za '.$period->label(),
            $due,
            sprintf('%s − %s', $taxYtd->format(''), $input->advancesAlreadyDue->format('')),
            'Termin: do 20. dnia miesiąca następnego',
        );

        if (! $input->costsRecorded) {
            $notes[] = $this->missingCostsNote($profile);
        }

        $solidarity = $version->money('solidarity_levy.threshold');
        if ($base->greaterThan($solidarity)) {
            $notes[] = sprintf(
                'Podstawa przekroczyła %s — powstaje obowiązek daniny solidarnościowej 4%% (PIT-DSF). '
                .'Danina jest roczna i NIE wchodzi do zaliczki miesięcznej.',
                $solidarity->format(),
            );
        }

        return new PitSettlement(
            $period, PitRegime::Scale, $input->revenueYearToDate, $input->costsYearToDate,
            $social, $base, $taxYtd, $input->advancesAlreadyDue, $due, $breakdown, $notes,
            ! $input->costsRecorded,
        );
    }

    private function missingCostsNote(TaxProfile $profile): string
    {
        return sprintf(
            'BRAK EWIDENCJI KOSZTÓW: %s opodatkowuje DOCHÓD (przychód minus koszty). Podano wyłącznie '
            .'sprzedaż z kasy fiskalnej, więc koszty przyjęto jako zero i wynik jest GÓRNĄ GRANICĄ '
            .'podatku, a nie zaliczką do zapłaty. Każdy udokumentowany koszt ją obniża. '
            .'Aby rozliczać się z samej sprzedaży, formą właściwą jest ryczałt.',
            $profile->pitRegime->label(),
        );
    }
}
