<?php

declare(strict_types=1);

namespace Poland\Calculators;

use Poland\Domain\Money;

/**
 * Cumulative figures the PIT advance is computed from.
 *
 * Assembled by the engine from the month-by-month ledger rather than accepted
 * from a caller, so a year's advances are always internally consistent.
 */
final class PitInput
{
    public readonly Money $lossCarriedForward;

    /** @param array<string,Money> $revenueByLumpSumRate */
    public function __construct(
        public readonly Money $revenueYearToDate,
        public readonly Money $costsYearToDate,
        public readonly Money $socialContributionsDeductibleYearToDate,
        public readonly Money $healthContributionsPaidYearToDate,
        public readonly Money $advancesAlreadyDue,
        public readonly array $revenueByLumpSumRate = [],
        /**
         * False when the taxpayer supplied no cost register at all. Distinct
         * from costs of zero: one is a fact, the other is an absence, and the
         * report must not present the second as the first.
         */
        public readonly bool $costsRecorded = true,
        ?Money $lossCarriedForward = null,
    ) {
        $this->lossCarriedForward = $lossCarriedForward ?? Money::zero();
    }
}
