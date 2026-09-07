<?php

declare(strict_types=1);

namespace Shop\Profit;

use Shop\Truth\Certainty;
use Shop\Truth\Money;

/**
 * One product, one channel — §17.
 *
 * The ingredient cost carries its own certainty because it usually comes from a
 * recipe costed at last month's supplier prices. A margin computed from an
 * ESTIMATED cost is an ESTIMATED margin, and the class refuses to forget which
 * it is holding.
 */
final class ProductProfitability implements \JsonSerializable
{
    public readonly Money $packagingCost;

    public function __construct(
        public readonly string $productCode,
        public readonly string $productName,
        public readonly RevenueChannel $channel,
        public readonly Money $sellingPriceNet,
        public readonly Money $ingredientCost,
        public readonly float $channelCommissionPercent = 0.0,
        ?Money $packagingCost = null,
        public readonly Certainty $costCertainty = Certainty::ESTIMATED,
        public readonly int $unitsSold = 0,
    ) {
        if ($channelCommissionPercent < 0 || $channelCommissionPercent > 100) {
            throw new \InvalidArgumentException('A channel commission is a percentage between 0 and 100.');
        }

        $this->packagingCost = $packagingCost ?? Money::zero();
    }

    public function commission(): Money
    {
        return $this->sellingPriceNet->percent($this->channelCommissionPercent);
    }

    public function directCost(): Money
    {
        return $this->ingredientCost->plus($this->packagingCost);
    }

    public function contributionPerUnit(): Money
    {
        return $this->sellingPriceNet->minus($this->commission())->minus($this->directCost());
    }

    public function contributionMarginPercent(): ?float
    {
        return $this->contributionPerUnit()->shareOf($this->sellingPriceNet);
    }

    public function totalContribution(): Money
    {
        return $this->contributionPerUnit()->times($this->unitsSold);
    }

    public function isLossMaking(): bool
    {
        return $this->contributionPerUnit()->isNegative();
    }

    public function jsonSerialize(): array
    {
        return [
            'product_code' => $this->productCode,
            'product_name' => $this->productName,
            'channel' => $this->channel->value,
            'selling_price_net' => $this->sellingPriceNet->grosze,
            'ingredient_cost' => $this->ingredientCost->grosze,
            'packaging_cost' => $this->packagingCost->grosze,
            'commission' => $this->commission()->grosze,
            'contribution_per_unit' => $this->contributionPerUnit()->grosze,
            'contribution_margin_percent' => $this->contributionMarginPercent(),
            'units_sold' => $this->unitsSold,
            'total_contribution' => $this->totalContribution()->grosze,
            'cost_certainty' => $this->costCertainty->value,
            'loss_making' => $this->isLossMaking(),
        ];
    }
}
