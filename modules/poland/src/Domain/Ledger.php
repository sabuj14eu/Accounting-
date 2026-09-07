<?php

declare(strict_types=1);

namespace Poland\Domain;

/**
 * Everything recorded for a taxpayer, month by month.
 *
 * Deliberately sparse: a month with no entry is a month with NO DATA, which is
 * not the same as a month with zero sales. The engine distinguishes the two and
 * reports the difference, because a missing March silently treated as zero is
 * how a cumulative annual advance goes wrong for the rest of the year.
 */
final class Ledger
{
    /** @var array<string,FiscalSalesReport> */
    private array $sales = [];

    /** @var array<string,PurchaseRegister> */
    private array $purchases = [];

    /** @var array<string,Money> ZUS contributions paid in a month, for the cash basis. */
    private array $openingContributions = [];

    public function recordSales(FiscalSalesReport $report): self
    {
        $this->sales[$report->period->toString()] = $report;

        return $this;
    }

    public function recordPurchases(PurchaseRegister $register): self
    {
        $this->purchases[$register->period->toString()] = $register;

        return $this;
    }

    /**
     * Contributions carried in from before the ledger starts — the December
     * contributions paid in January, which the cash basis needs and which no
     * amount of arithmetic on this year's data can recover.
     */
    public function recordOpeningContributions(Period $period, Money $social, Money $health): self
    {
        $this->openingContributions[$period->toString()] = ['social' => $social, 'health' => $health];

        return $this;
    }

    public function salesFor(Period $period): ?FiscalSalesReport
    {
        return $this->sales[$period->toString()] ?? null;
    }

    public function purchasesFor(Period $period): ?PurchaseRegister
    {
        return $this->purchases[$period->toString()] ?? null;
    }

    /** @return array{social: Money, health: Money}|null */
    public function openingContributionsFor(Period $period): ?array
    {
        return $this->openingContributions[$period->toString()] ?? null;
    }

    public function hasSales(Period $period): bool
    {
        return isset($this->sales[$period->toString()]);
    }

    public function hasAnyPurchases(): bool
    {
        return $this->purchases !== [];
    }

    /**
     * Months of the given year up to $through that have no sales entry.
     *
     * @return list<Period>
     */
    public function missingMonths(Period $through): array
    {
        $missing = [];
        foreach ($through->yearToDate() as $month) {
            if (! $this->hasSales($month)) {
                $missing[] = $month;
            }
        }

        return $missing;
    }

    /** @return list<Period> */
    public function recordedPeriods(): array
    {
        $periods = array_map(
            static fn (FiscalSalesReport $r): Period => $r->period,
            array_values($this->sales),
        );

        usort($periods, static fn (Period $a, Period $b): int => $a->sortKey() <=> $b->sortKey());

        return $periods;
    }
}
