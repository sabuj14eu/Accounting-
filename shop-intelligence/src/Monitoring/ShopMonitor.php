<?php

declare(strict_types=1);

namespace Shop\Monitoring;

use Shop\Cash\CashLedger;
use Shop\Inventory\StockReconciliation;
use Shop\Platforms\PlatformSettlement;
use Shop\Profit\ProfitStatement;
use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;

/**
 * The automatic watch — §19.
 *
 * Four families of problem: revenue, inventory, cost, profit. Every alert it
 * raises is a ReviewFlag, which means every alert carries innocent explanations
 * and none of them can be phrased as an accusation. An alert with nothing to
 * compare against is not raised at all; silence from too little history is
 * reported as NOT ENOUGH HISTORY rather than as "all normal".
 */
final class ShopMonitor
{
    public function __construct(
        public readonly float $revenueDropPercent = -15.0,
        public readonly float $costRisePercent = 20.0,
        public readonly float $marginDropPoints = 5.0,
    ) {
    }

    /**
     * @param  array<string, Money>  $revenueHistory  period => revenue, oldest first
     * @param  array<string, Money>  $costHistory  period => total costs, oldest first
     * @param  list<PlatformSettlement>  $settlements
     * @return list<ReviewFlag>
     */
    public function run(
        ProfitStatement $statement,
        array $revenueHistory = [],
        array $costHistory = [],
        ?StockReconciliation $stock = null,
        ?CashLedger $cash = null,
        array $settlements = [],
        ?float $previousMarginPercent = null,
    ): array {
        $flags = [];

        foreach ($this->revenueAlerts($statement, $revenueHistory) as $flag) {
            $flags[] = $flag;
        }

        foreach ($this->costAlerts($statement, $costHistory) as $flag) {
            $flags[] = $flag;
        }

        foreach ($this->profitAlerts($statement, $previousMarginPercent) as $flag) {
            $flags[] = $flag;
        }

        foreach ($statement->reviewFlags() as $flag) {
            $flags[] = $flag;
        }

        if ($stock !== null) {
            foreach ($stock->reviewFlags() as $flag) {
                $flags[] = $flag;
            }
        }

        if ($cash !== null) {
            foreach ($cash->reviewFlags() as $flag) {
                $flags[] = $flag;
            }
        }

        foreach ($settlements as $settlement) {
            foreach ($settlement->reviewFlags() as $flag) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * @param  array<string, Money>  $history
     * @return list<ReviewFlag>
     */
    private function revenueAlerts(ProfitStatement $statement, array $history): array
    {
        $baseline = new Baseline($history);

        if (! $baseline->hasEnoughHistory()) {
            return [];
        }

        $actual = $statement->revenueTotal()->amount;
        $deviation = $baseline->deviationPercent($actual);

        if ($deviation === null || $deviation > $this->revenueDropPercent) {
            return [];
        }

        $shortfall = $baseline->median()->minus($actual);

        return [new ReviewFlag(
            'REVENUE_BELOW_NORMAL',
            'revenue, '.$statement->period,
            sprintf(
                'Revenue is %.1f%% below the median of the last %d periods (%s against %s).',
                abs($deviation),
                count($history),
                $actual->format(),
                $baseline->median()->format(),
            ),
            [
                'the period is not finished and not all sales are entered yet',
                'a platform statement or a fiscal report is still missing',
                'seasonal — the same month is quieter every year',
                'the shop was closed for holidays, repairs or staff shortage',
                'a delivery platform was down or the shop was toggled offline on it',
            ],
            ReviewFlag::SEVERITY_REVIEW,
            $shortfall->absolute(),
        )];
    }

    /**
     * @param  array<string, Money>  $history
     * @return list<ReviewFlag>
     */
    private function costAlerts(ProfitStatement $statement, array $history): array
    {
        $baseline = new Baseline($history);

        if (! $baseline->hasEnoughHistory()) {
            return [];
        }

        $actual = $statement->costTotal()->amount;
        $deviation = $baseline->deviationPercent($actual);

        if ($deviation === null || $deviation < $this->costRisePercent) {
            return [];
        }

        $excess = $actual->minus($baseline->median());

        return [new ReviewFlag(
            'COSTS_ABOVE_NORMAL',
            'costs, '.$statement->period,
            sprintf(
                'Costs are %.1f%% above the median of the last %d periods (%s against %s).',
                $deviation,
                count($history),
                $actual->format(),
                $baseline->median()->format(),
            ),
            [
                'a quarterly or annual bill fell in this period — insurance, licence, equipment',
                'stock was bought ahead of a busy period and has not been sold yet',
                'an invoice was imported twice, from two sources',
                'supplier prices rose',
                'a repair or a replacement that will not repeat',
            ],
            ReviewFlag::SEVERITY_REVIEW,
            $excess,
        )];
    }

    /** @return list<ReviewFlag> */
    private function profitAlerts(ProfitStatement $statement, ?float $previousMarginPercent): array
    {
        $margin = $statement->marginPercent();

        if ($margin !== null && $margin < 0) {
            return [new ReviewFlag(
                'MANAGEMENT_LOSS',
                'management profit, '.$statement->period,
                'This period is at a management loss of '
                    .$statement->managementProfitAmount()->absolute()->format().'.',
                [
                    'costs from several periods landed in this one',
                    'revenue for the period is not fully entered yet',
                    'a one-off purchase — equipment, a deposit, a bulk order — is counted here in full',
                ],
                ReviewFlag::SEVERITY_URGENT,
                $statement->managementProfitAmount()->absolute(),
            )];
        }

        if ($margin === null || $previousMarginPercent === null) {
            return [];
        }

        $drop = $previousMarginPercent - $margin;

        if ($drop < $this->marginDropPoints) {
            return [];
        }

        return [new ReviewFlag(
            'MARGIN_FELL',
            'management margin, '.$statement->period,
            sprintf(
                'The management margin fell %.1f points, from %.1f%% to %.1f%%.',
                $drop,
                $previousMarginPercent,
                $margin,
            ),
            [
                'the sales mix moved toward lower-margin products or higher-commission channels',
                'ingredient prices rose without selling prices following',
                'portion sizes drifted upward',
                'a one-off cost sits in this period',
            ],
            ReviewFlag::SEVERITY_REVIEW,
        )];
    }
}
