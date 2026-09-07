<?php

declare(strict_types=1);

namespace Poland\Domain\Enums;

/**
 * Form of PIT taxation chosen by the sole trader (JDG).
 *
 * Nothing in this package assumes a regime. The taxpayer configures one and
 * the calculators branch on it, because the three regimes do not merely use
 * different rates — they tax different bases.
 */
enum PitRegime: string
{
    /** Skala podatkowa — 12% / 32% on income (revenue less costs less social ZUS). */
    case Scale = 'scale';

    /** Podatek liniowy — flat 19% on income. */
    case Flat = 'flat';

    /** Ryczałt od przychodów ewidencjonowanych — a rate on REVENUE, costs are not deductible. */
    case LumpSum = 'lump_sum';

    public function label(): string
    {
        return match ($this) {
            self::Scale => 'Skala podatkowa (12% / 32%)',
            self::Flat => 'Podatek liniowy (19%)',
            self::LumpSum => 'Ryczałt od przychodów ewidencjonowanych',
        };
    }

    /**
     * Whether business costs reduce the PIT base at all.
     *
     * This is the reason a cash-register-only workflow is exact on ryczałt and
     * only an estimate on the other two: without a cost register, the Scale and
     * Flat bases are unknown, not zero.
     */
    public function deductsCosts(): bool
    {
        return $this !== self::LumpSum;
    }

    /** Whether the health contribution reduces the PIT base, and how. */
    public function healthDeduction(): HealthDeduction
    {
        return match ($this) {
            // No health deduction on the tax scale since 2022.
            self::Scale => HealthDeduction::None,
            // Flat tax: paid health contributions deductible up to an annual cap.
            self::Flat => HealthDeduction::CappedAnnualLimit,
            // Ryczałt: 50% of paid health contributions reduces revenue.
            self::LumpSum => HealthDeduction::HalfOfPaid,
        };
    }
}
