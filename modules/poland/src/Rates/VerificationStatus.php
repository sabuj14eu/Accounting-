<?php

declare(strict_types=1);

namespace Poland\Rates;

/**
 * How well a rate version's numbers are sourced.
 *
 * This is a first-class property of every rate, not a comment, because the
 * difference between "an accountant confirmed this against the ZUS
 * announcement" and "this was read in the trade press and the arithmetic
 * checks out" is the difference between a figure you may file and a figure you
 * may only look at.
 */
enum VerificationStatus: string
{
    /**
     * Confirmed against the issuing authority's own publication — the statute,
     * the ZUS/MF announcement, the GUS communiqué, the Dziennik Ustaw entry.
     * Only this status is fit to produce a figure that will be filed.
     */
    case Official = 'official';

    /**
     * Taken from a competent secondary source (accounting press, advisory
     * firms) and cross-checked arithmetically against its own stated formula.
     * Useful, and NOT a production source of truth.
     */
    case Secondary = 'secondary';

    /** Entered but not checked against anything. Never usable. */
    case Unverified = 'unverified';

    public function label(): string
    {
        return match ($this) {
            self::Official => 'Zweryfikowane w źródle urzędowym',
            self::Secondary => 'Źródło wtórne — wymaga potwierdzenia urzędowego',
            self::Unverified => 'NIEZWERYFIKOWANE',
        };
    }

    /** Whether a figure produced from this rate may be presented for filing. */
    public function fitForFiling(): bool
    {
        return $this === self::Official;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Official => 0,
            self::Secondary => 1,
            self::Unverified => 2,
        };
    }
}
