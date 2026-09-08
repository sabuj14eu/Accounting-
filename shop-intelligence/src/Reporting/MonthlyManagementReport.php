<?php

declare(strict_types=1);

namespace Shop\Reporting;

use Shop\Cash\CashLedger;
use Shop\Comparison\AccountsComparison;
use Shop\Costs\CostCategory;
use Shop\Inventory\StockReconciliation;
use Shop\Monitoring\ShopMonitor;
use Shop\Platforms\PlatformSettlement;
use Shop\Profit\ChannelResult;
use Shop\Profit\ProfitStatement;
use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;

/**
 * The monthly management report — §20.
 *
 * One object holding the month, assembled once and rendered many ways. It
 * carries the banner in the domain rather than in a template so that no future
 * template can drop it, and it exposes the loss ranking as part of the report
 * rather than as a separate screen, because §21's question is the one an owner
 * actually opens the application to ask.
 */
final class MonthlyManagementReport implements \JsonSerializable
{
    public const BANNER =
        'SHOP INTELLIGENCE — MANAGEMENT ANALYSIS. Not an accounting record, not a tax calculation, '
        .'not a filing. Official figures come from the accounting application.';

    /**
     * @param  list<ChannelResult>  $channels
     * @param  list<PlatformSettlement>  $settlements
     * @param  array<string, Money>  $revenueHistory
     * @param  array<string, Money>  $costHistory
     */
    public function __construct(
        public readonly ProfitStatement $statement,
        public readonly array $channels = [],
        public readonly array $settlements = [],
        public readonly ?StockReconciliation $stock = null,
        public readonly ?CashLedger $cash = null,
        public readonly ?AccountsComparison $comparison = null,
        public readonly array $revenueHistory = [],
        public readonly array $costHistory = [],
        public readonly ?float $previousMarginPercent = null,
        public readonly string $generatedAt = '',
        ?ShopMonitor $monitor = null,
    ) {
        // The thresholds the monitor runs with are the caller's decision and
        // travel with the report. A report that silently built its own monitor
        // would ignore every configured threshold.
        $this->monitor = $monitor ?? new ShopMonitor();
    }

    public readonly ShopMonitor $monitor;

    public function period(): string
    {
        return $this->statement->period;
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        $flags = $this->monitor->run(
            $this->statement,
            $this->revenueHistory,
            $this->costHistory,
            $this->stock,
            $this->cash,
            $this->settlements,
            $this->previousMarginPercent,
        );

        if ($this->comparison !== null) {
            foreach ($this->comparison->reviewFlags() as $flag) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    public function lossRanking(): LossRanking
    {
        return new LossRanking($this->reviewFlags());
    }

    /** @return list<ChannelResult> best contribution margin first */
    public function channelsByMargin(): array
    {
        $channels = $this->channels;
        usort(
            $channels,
            static fn (ChannelResult $a, ChannelResult $b) => ($b->contributionMarginPercent() ?? -INF)
                <=> ($a->contributionMarginPercent() ?? -INF),
        );

        return $channels;
    }

    public function jsonSerialize(): array
    {
        return [
            'banner' => self::BANNER,
            'period' => $this->period(),
            'generated_at' => $this->generatedAt,
            'profit' => $this->statement,
            'channels' => $this->channelsByMargin(),
            'platform_settlements' => $this->settlements,
            'stock' => $this->stock,
            'cash' => $this->cash,
            'accounts_comparison' => $this->comparison,
            'loss_ranking' => $this->lossRanking(),
        ];
    }

    /** @return array<string, string> the cost sections, formatted for display */
    public function costSections(): array
    {
        $sections = [];
        foreach (CostCategory::cases() as $category) {
            $sections[$category->label()] = $this->statement->costsFor($category)->describe();
        }

        return $sections;
    }
}
