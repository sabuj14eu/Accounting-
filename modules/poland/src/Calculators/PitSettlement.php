<?php

declare(strict_types=1);

namespace Poland\Calculators;

use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Reporting\Breakdown;

/**
 * The PIT advance for one month.
 *
 * Polish PIT advances are cumulative: the advance for a month is the tax on
 * income earned since 1 January less the advances already due for earlier
 * months. So this object carries both the year-to-date figures and the month's
 * own amount, and the two must always be reported together — a monthly number
 * without its cumulative context cannot be checked.
 */
final class PitSettlement implements \JsonSerializable
{
    /** @param list<string> $notes */
    public function __construct(
        public readonly Period $period,
        public readonly PitRegime $regime,
        public readonly Money $revenueYearToDate,
        public readonly Money $costsYearToDate,
        public readonly Money $deductionsYearToDate,
        public readonly Money $taxBaseYearToDate,
        public readonly Money $taxYearToDate,
        public readonly Money $advancesAlreadyDue,
        /** The amount to pay for this month. */
        public readonly Money $advanceDue,
        public readonly Breakdown $breakdown,
        public readonly array $notes = [],
        public readonly bool $isEstimate = false,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period->toString(),
            'regime' => $this->regime->value,
            'revenue_ytd' => $this->revenueYearToDate,
            'costs_ytd' => $this->costsYearToDate,
            'deductions_ytd' => $this->deductionsYearToDate,
            'tax_base_ytd' => $this->taxBaseYearToDate,
            'tax_ytd' => $this->taxYearToDate,
            'advances_already_due' => $this->advancesAlreadyDue,
            'advance_due' => $this->advanceDue,
            'is_estimate' => $this->isEstimate,
            'notes' => $this->notes,
            'breakdown' => $this->breakdown,
        ];
    }
}
