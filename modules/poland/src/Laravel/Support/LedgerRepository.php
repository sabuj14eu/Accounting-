<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Poland\Domain\Ledger;
use Poland\Domain\Period;
use Poland\Laravel\Models\PurchaseSummaryModel;
use Poland\Laravel\Models\SalesReportModel;
use Poland\Laravel\Models\TaxProfileModel;

/**
 * Assembles the domain ledger for a taxpayer's year from stored rows.
 *
 * Only reports in force are loaded: a superseded report stays in the database
 * for audit but must never re-enter a calculation, or a correction would be
 * counted twice.
 */
final class LedgerRepository
{
    public function forYear(TaxProfileModel $profile, int $year, bool $includePreviousDecember = true): Ledger
    {
        $ledger = new Ledger();

        $periods = array_map(
            static fn (int $m): string => sprintf('%04d-%02d', $year, $m),
            range(1, 12),
        );

        if ($includePreviousDecember) {
            // December feeds the health contribution for January under the
            // scale and the flat tax, which look back one month.
            $periods[] = sprintf('%04d-12', $year - 1);
        }

        SalesReportModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->inForce()
            ->whereIn('period', $periods)
            ->with('lines')
            ->get()
            ->each(static fn (SalesReportModel $report) => $ledger->recordSales($report->toDomain()));

        PurchaseSummaryModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->whereIn('period', $periods)
            ->get()
            ->each(static fn (PurchaseSummaryModel $summary) => $ledger->recordPurchases($summary->toDomain()));

        return $ledger;
    }

    public function forPeriod(TaxProfileModel $profile, Period $period): Ledger
    {
        return $this->forYear($profile, $period->year);
    }
}
