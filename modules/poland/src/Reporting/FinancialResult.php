<?php

declare(strict_types=1);

namespace Poland\Reporting;

use Poland\Domain\Money;
use Poland\Domain\Period;

/**
 * Revenue, costs and result for the month and year to date.
 *
 * `costsRecorded` is carried through to the surface rather than being inferred
 * from a zero: with no cost register, "income" is revenue and that is an UPPER
 * BOUND on profit, not a profit figure. The distinction is preserved all the
 * way to the printed page.
 */
final class FinancialResult implements \JsonSerializable
{
    public function __construct(
        public readonly Period $period,
        public readonly Money $revenueMonth,
        public readonly Money $costsMonth,
        public readonly Money $revenueYearToDate,
        public readonly Money $costsYearToDate,
        public readonly bool $costsRecorded,
        /** True when income tax is levied on revenue (ryczałt), where costs are irrelevant. */
        public readonly bool $taxedOnRevenue,
    ) {
    }

    public function incomeMonth(): Money
    {
        return $this->revenueMonth->minus($this->costsMonth);
    }

    public function incomeYearToDate(): Money
    {
        return $this->revenueYearToDate->minus($this->costsYearToDate);
    }

    /**
     * Whether the income figure is an upper bound rather than a result.
     *
     * On ryczałt this is false even with no cost register: the tax base is
     * revenue, so the tax is exact. The FINANCIAL result still is not — the
     * taxpayer's actual profit depends on costs nobody recorded — and
     * `profitIsKnown()` answers that separate question.
     */
    public function incomeIsUpperBound(): bool
    {
        return ! $this->costsRecorded;
    }

    public function profitIsKnown(): bool
    {
        return $this->costsRecorded;
    }

    public function caveat(): ?string
    {
        if ($this->costsRecorded) {
            return null;
        }

        return $this->taxedOnRevenue
            ? 'Brak ewidencji kosztów. Na ryczałcie podatek liczy się od PRZYCHODU, więc kwota '
                .'podatku jest dokładna — ale "dochód" poniżej to przychód pomniejszony o zero '
                .'kosztów, czyli GÓRNA GRANICA rzeczywistego zysku, a nie zysk.'
            : 'Brak ewidencji kosztów. Ta forma opodatkowania rozlicza DOCHÓD, więc zarówno wynik '
                .'finansowy, jak i podatek to GÓRNE GRANICE. Każdy udokumentowany koszt je obniża.';
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period->toString(),
            'month' => [
                'revenue' => $this->revenueMonth,
                'costs' => $this->costsMonth,
                'income' => $this->incomeMonth(),
            ],
            'year_to_date' => [
                'revenue' => $this->revenueYearToDate,
                'costs' => $this->costsYearToDate,
                'income' => $this->incomeYearToDate(),
            ],
            'costs_recorded' => $this->costsRecorded,
            'income_is_upper_bound' => $this->incomeIsUpperBound(),
            'profit_is_known' => $this->profitIsKnown(),
            'caveat' => $this->caveat(),
        ];
    }
}
