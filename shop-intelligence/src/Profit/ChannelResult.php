<?php

declare(strict_types=1);

namespace Shop\Profit;

use Shop\Truth\Money;

/**
 * One channel's contribution — §17.
 *
 *     gross revenue − platform commission − direct product cost = contribution
 *
 * Deliberately NOT called profit. It carries no share of rent, wages or
 * electricity, and an owner who reads "Glovo profit 18%" and then discovers
 * the rent has to come out of it has been misled by a word.
 */
final class ChannelResult implements \JsonSerializable
{
    public function __construct(
        public readonly RevenueChannel $channel,
        public readonly Money $grossRevenue,
        public readonly Money $commission,
        public readonly Money $directProductCost,
        public readonly int $orderCount = 0,
    ) {
    }

    public function netRevenue(): Money
    {
        return $this->grossRevenue->minus($this->commission);
    }

    public function contribution(): Money
    {
        return $this->netRevenue()->minus($this->directProductCost);
    }

    public function contributionMarginPercent(): ?float
    {
        return $this->contribution()->shareOf($this->grossRevenue);
    }

    public function commissionPercent(): ?float
    {
        return $this->commission->shareOf($this->grossRevenue);
    }

    public function averageOrderValue(): ?Money
    {
        if ($this->orderCount <= 0) {
            return null;
        }

        return Money::grosze(intdiv($this->grossRevenue->grosze, $this->orderCount));
    }

    public function jsonSerialize(): array
    {
        return [
            'channel' => $this->channel->value,
            'label' => $this->channel->label(),
            'gross_revenue' => $this->grossRevenue->grosze,
            'commission' => $this->commission->grosze,
            'net_revenue' => $this->netRevenue()->grosze,
            'direct_product_cost' => $this->directProductCost->grosze,
            'contribution' => $this->contribution()->grosze,
            'contribution_margin_percent' => $this->contributionMarginPercent(),
            'commission_percent' => $this->commissionPercent(),
            'order_count' => $this->orderCount,
            'average_order_value' => $this->averageOrderValue()?->grosze,
        ];
    }
}
