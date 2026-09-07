<?php

declare(strict_types=1);

namespace Shop\Comparison;

use Shop\Profit\ProfitStatement;
use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;

/**
 * Shop Intelligence beside the official accounts — §27.
 *
 * The two are EXPECTED to differ, and the differences are informative rather
 * than wrong: this side counts declared cash the accounts may not have seen,
 * counts expected costs that have not been booked, ignores tax timing rules
 * entirely, and includes costs the tax code would disallow. So the comparison
 * reports the gap and what usually causes it.
 *
 * It cannot do anything else. There is no method here that writes, corrects,
 * adjusts or reconciles — §2 and §28 forbid write access to the accounting
 * system, and the absence of the method is the proof.
 */
final class AccountsComparison implements \JsonSerializable
{
    public function __construct(
        public readonly ProfitStatement $statement,
        public readonly AccountsSnapshot $official,
        public readonly float $tolerancePercent = 2.0,
    ) {
        if ($statement->period !== $official->period) {
            throw new \InvalidArgumentException(
                "Cannot compare {$statement->period} with {$official->period}: different periods."
            );
        }
    }

    public function revenueDifference(): Money
    {
        return $this->statement->revenueTotal()->amount->minus($this->official->revenue);
    }

    public function costDifference(): Money
    {
        return $this->statement->costTotal()->amount->minus($this->official->costs);
    }

    public function resultDifference(): Money
    {
        return $this->statement->managementProfitAmount()->minus($this->official->result());
    }

    public function isWithinTolerance(): bool
    {
        $share = $this->revenueDifference()->absolute()->shareOf($this->official->revenue->absolute());

        return $share !== null && $share <= $this->tolerancePercent;
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        $flags = [];

        $revenueGap = $this->revenueDifference();
        if (! $revenueGap->isZero() && ! $this->isWithinTolerance()) {
            $flags[] = new ReviewFlag(
                'REVENUE_DIFFERS_FROM_ACCOUNTS',
                'revenue, '.$this->statement->period,
                'This application records '.$revenueGap->absolute()->format()
                    .($revenueGap->isPositive() ? ' more ' : ' less ')
                    .'revenue than the official accounts snapshot for the same period ('
                    .$this->statement->revenueTotal()->amount->format().' against '
                    .$this->official->revenue->format().').',
                [
                    'this side records gross platform orders where the accounts record the net payout',
                    'a platform payout falls in a different month on each side',
                    'the accounts use invoice dates and this side uses when the money moved',
                    'declared cash recorded here has not reached the accounting system yet',
                    'the snapshot was taken before the month was closed on the accounting side',
                ],
                ReviewFlag::SEVERITY_REVIEW,
                $revenueGap->absolute(),
            );
        }

        $costGap = $this->costDifference();
        if (! $costGap->isZero()) {
            $expected = $this->statement->expectedCostsIncluded();
            $flags[] = new ReviewFlag(
                'COSTS_DIFFER_FROM_ACCOUNTS',
                'costs, '.$this->statement->period,
                'This application records '.$costGap->absolute()->format()
                    .($costGap->isPositive() ? ' more ' : ' less ')
                    .'cost than the official accounts snapshot'
                    .($expected->isPositive()
                        ? ', of which '.$expected->format().' is scheduled but not yet confirmed'
                        : '').'.',
                [
                    'costs the tax rules disallow are counted here and not there',
                    'expected recurring costs are counted here before the invoice arrives',
                    'the accounts apply VAT netting that this side does not',
                    'a supplier invoice reached one side and not the other',
                ],
                ReviewFlag::SEVERITY_INFO,
                $costGap->absolute(),
            );
        }

        return $flags;
    }

    public function verdict(): string
    {
        return 'COMPARISON ONLY — differences here are investigated, never corrected automatically, '
            .'and nothing in this application writes to the accounting system.';
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->statement->period,
            'shop_revenue' => $this->statement->revenueTotal()->amount->grosze,
            'official_revenue' => $this->official->revenue->grosze,
            'revenue_difference' => $this->revenueDifference()->grosze,
            'shop_costs' => $this->statement->costTotal()->amount->grosze,
            'official_costs' => $this->official->costs->grosze,
            'cost_difference' => $this->costDifference()->grosze,
            'result_difference' => $this->resultDifference()->grosze,
            'within_tolerance' => $this->isWithinTolerance(),
            'verdict' => $this->verdict(),
            'review_flags' => $this->reviewFlags(),
        ];
    }
}
