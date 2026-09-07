<?php

declare(strict_types=1);

namespace Poland\Domain\Enums;

/**
 * Which month's ZUS contributions reduce a given month's PIT.
 *
 * The two answers differ by one month and by up to one month's contributions,
 * and they converge over a full calendar year. They are NOT interchangeable in
 * a single month, so the engine never picks silently: the basis is part of the
 * taxpayer profile and is printed on every report that used it.
 */
enum ContributionDeductionBasis: string
{
    /**
     * Deduct the contributions accrued FOR the settled month (memoriałowo).
     * Simpler, and exact over a full year.
     */
    case AccruedForMonth = 'accrued_for_month';

    /**
     * Deduct the contributions actually PAID during the settled month
     * (kasowo) — which for a JDG are the previous month's contributions,
     * paid by the 20th. This is the strict reading of the statute, and it
     * needs an opening balance for January.
     */
    case PaidInMonth = 'paid_in_month';

    public function label(): string
    {
        return match ($this) {
            self::AccruedForMonth => 'Składki za dany miesiąc (ujęcie memoriałowe)',
            self::PaidInMonth => 'Składki zapłacone w danym miesiącu (ujęcie kasowe)',
        };
    }
}
