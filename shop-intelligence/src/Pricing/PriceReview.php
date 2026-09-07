<?php

declare(strict_types=1);

namespace Shop\Pricing;

use Shop\Profit\ProductProfitability;
use Shop\Truth\Certainty;
use Shop\Truth\Money;

/**
 * The price review signal — §17.
 *
 * Green, amber or red, with the reason spelled out, and NO price ever changed.
 * There is no method on this class that returns a new price, and that omission
 * is the feature: a system that can move prices by itself will eventually move
 * one at 3 a.m. because a supplier invoice was double-imported.
 *
 * The signal also degrades honestly. If the ingredient cost behind it is
 * ESTIMATED — and it nearly always is, being recipe-costed — the review says
 * so and never reads GREEN with more confidence than its inputs deserve.
 */
final class PriceReview implements \JsonSerializable
{
    public const GREEN = 'GREEN';
    public const AMBER = 'AMBER';
    public const RED = 'RED';

    public function __construct(
        public readonly ProductProfitability $product,
        public readonly float $targetMarginPercent = 65.0,
        public readonly float $warningMarginPercent = 55.0,
    ) {
        if ($warningMarginPercent > $targetMarginPercent) {
            throw new \InvalidArgumentException('The warning margin cannot be above the target margin.');
        }
    }

    public function signal(): string
    {
        if ($this->product->isLossMaking()) {
            return self::RED;
        }

        $margin = $this->product->contributionMarginPercent();

        if ($margin === null) {
            // A product sold at zero: nothing to divide by, nothing to conclude.
            return self::RED;
        }

        if ($margin >= $this->targetMarginPercent) {
            return self::GREEN;
        }

        return $margin >= $this->warningMarginPercent ? self::AMBER : self::RED;
    }

    public function emoji(): string
    {
        return match ($this->signal()) {
            self::GREEN => '🟢',
            self::AMBER => '🟡',
            self::RED => '🔴',
        };
    }

    /** How much the price would have to move to reach the target — advice, not an action. */
    public function priceGapToTarget(): Money
    {
        $margin = $this->product->contributionMarginPercent();

        if ($margin !== null && $margin >= $this->targetMarginPercent) {
            return Money::zero();
        }

        // Solve: (price·(1 − commission%) − directCost) / price = target%
        $commissionFraction = $this->product->channelCommissionPercent / 100;
        $targetFraction = $this->targetMarginPercent / 100;
        $denominator = (1 - $commissionFraction) - $targetFraction;

        if ($denominator <= 0) {
            // The commission alone eats the target margin; no price reaches it.
            return Money::zero();
        }

        $required = Money::grosze((int) ceil($this->product->directCost()->grosze / $denominator));
        $gap = $required->minus($this->product->sellingPriceNet);

        return $gap->isPositive() ? $gap : Money::zero();
    }

    public function explanation(): string
    {
        $p = $this->product;
        $margin = $p->contributionMarginPercent();
        $where = $p->channel->label();

        $basis = sprintf(
            '%s on %s: sells for %s, ingredients and packaging %s%s, leaving %s per unit%s.',
            $p->productName,
            $where,
            $p->sellingPriceNet->format(),
            $p->directCost()->format(),
            $p->channelCommissionPercent > 0.0
                ? sprintf(', commission %s (%.1f%%)', $p->commission()->format(), $p->channelCommissionPercent)
                : '',
            $p->contributionPerUnit()->format(),
            $margin === null ? '' : sprintf(' (%.1f%%)', $margin),
        );

        $verdict = match ($this->signal()) {
            self::GREEN => sprintf('At or above the %.0f%% target.', $this->targetMarginPercent),
            self::AMBER => sprintf(
                'Below the %.0f%% target but still contributing. A rise of %s would reach the target.',
                $this->targetMarginPercent,
                $this->priceGapToTarget()->format(),
            ),
            self::RED => $p->isLossMaking()
                ? 'Every unit sold on this channel loses money before rent, wages or electricity.'
                : sprintf('Well below the %.0f%% warning level.', $this->warningMarginPercent),
        };

        $caveat = $p->costCertainty === Certainty::ACTUAL
            ? ''
            : ' The ingredient cost behind this is '.$p->costCertainty->label()
                .', so treat the margin as indicative rather than exact.';

        return $this->emoji().' '.$basis.' '.$verdict.$caveat
            .' This is a review, not a price change — nothing has been altered.';
    }

    public function jsonSerialize(): array
    {
        return [
            'product_code' => $this->product->productCode,
            'channel' => $this->product->channel->value,
            'signal' => $this->signal(),
            'emoji' => $this->emoji(),
            'contribution_margin_percent' => $this->product->contributionMarginPercent(),
            'target_margin_percent' => $this->targetMarginPercent,
            'price_gap_to_target' => $this->priceGapToTarget()->grosze,
            'explanation' => $this->explanation(),
            'price_changed' => false,
        ];
    }
}
