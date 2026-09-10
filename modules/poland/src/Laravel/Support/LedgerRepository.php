<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Poland\Domain\Ledger;
use Poland\Domain\Period;
use Poland\Laravel\Models\PurchaseSummaryModel;
use Poland\Laravel\Models\SalesReportModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Purchases\RegisterResolution;

/**
 * Assembles the domain ledger for a taxpayer's year from stored rows.
 *
 * Only reports in force are loaded: a superseded report stays in the database
 * for audit but must never re-enter a calculation, or a correction would be
 * counted twice.
 */
final class LedgerRepository
{
    public function __construct(private readonly InvoicePostingService $postings)
    {
    }

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

        foreach ($this->resolvePurchases($profile, $periods) as $resolution) {
            if ($resolution->register !== null) {
                $ledger->recordPurchases($resolution->register);
            }
        }

        return $ledger;
    }

    /**
     * One purchase register per month, from ONE source.
     *
     * Posted invoices win when they are the only source; the manual monthly
     * total keeps working for months before postings existed; a month with
     * both (and the manual one not superseded) is a CONFLICT that gets NO
     * register, so the engine reports an upper bound instead of a sum.
     *
     * @param list<string> $periods
     * @return list<RegisterResolution>
     */
    public function resolvePurchases(TaxProfileModel $profile, array $periods): array
    {
        $manual = PurchaseSummaryModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->whereIn('period', $periods)
            ->whereNull('superseded_at')
            ->get()
            ->keyBy('period');

        $resolutions = [];
        foreach ($periods as $period) {
            $month = Period::parse($period);
            $resolutions[] = RegisterResolution::resolve(
                $month,
                $this->postings->registerFromPostings($profile, $month),
                $manual->has($period) ? $manual->get($period)->toDomain() : null,
            );
        }

        return $resolutions;
    }

    /** @return list<RegisterResolution> the months of $period's year that have two sources */
    public function conflictsFor(TaxProfileModel $profile, Period $period): array
    {
        $periods = array_map(
            static fn (int $m): string => sprintf('%04d-%02d', $period->year, $m),
            range(1, 12),
        );

        return array_values(array_filter(
            $this->resolvePurchases($profile, $periods),
            static fn (RegisterResolution $r): bool => $r->isConflict(),
        ));
    }

    public function forPeriod(TaxProfileModel $profile, Period $period): Ledger
    {
        return $this->forYear($profile, $period->year);
    }
}
