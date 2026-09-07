<?php

declare(strict_types=1);

namespace Shop\Inventory;

use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;

/**
 * A period's stock check across every tracked ingredient, plus food cost — §9.
 *
 * Two food-cost numbers, never one. THEORETICAL food cost is what the recipes
 * say the sold portions should have cost; ACTUAL food cost is what left the
 * shelf. The gap between them is the number a shop owner actually wants, and
 * quoting either alone is how a kitchen loses money quietly for a year.
 */
final class StockReconciliation implements \JsonSerializable
{
    /** @param list<StockLine> $lines */
    public function __construct(
        public readonly string $period,
        public readonly array $lines,
        public readonly ?Money $revenue = null,
        public readonly ?Money $theoreticalFoodCost = null,
        public readonly ?Money $actualFoodCost = null,
    ) {
    }

    /** @return list<StockLine> */
    public function linesRequiringReview(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (StockLine $line) => $line->status() === StockLine::REQUIRES_REVIEW,
        ));
    }

    /** @return list<StockLine> */
    public function uncountedLines(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (StockLine $line) => $line->status() === StockLine::NOT_COUNTED,
        ));
    }

    public function isComplete(): bool
    {
        return $this->lines !== [] && $this->uncountedLines() === [];
    }

    /** Total value of every unexplained difference, where a unit cost is known. */
    public function unexplainedValue(): Money
    {
        $total = Money::zero();
        foreach ($this->linesRequiringReview() as $line) {
            $impact = $line->financialImpact();
            if ($impact !== null) {
                $total = $total->plus($impact);
            }
        }

        return $total;
    }

    public function theoreticalFoodCostPercent(): ?float
    {
        if ($this->theoreticalFoodCost === null || $this->revenue === null) {
            return null;
        }

        return $this->theoreticalFoodCost->shareOf($this->revenue);
    }

    public function actualFoodCostPercent(): ?float
    {
        if ($this->actualFoodCost === null || $this->revenue === null) {
            return null;
        }

        return $this->actualFoodCost->shareOf($this->revenue);
    }

    /** Actual minus theoretical: the cost of everything the recipes did not predict. */
    public function foodCostGap(): ?Money
    {
        if ($this->actualFoodCost === null || $this->theoreticalFoodCost === null) {
            return null;
        }

        return $this->actualFoodCost->minus($this->theoreticalFoodCost);
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        $flags = [];

        foreach ($this->lines as $line) {
            foreach ($line->reviewFlags() as $flag) {
                $flags[] = $flag;
            }
        }

        $gap = $this->foodCostGap();
        if ($gap !== null && $gap->isPositive()) {
            $percent = $gap->shareOf($this->theoreticalFoodCost);
            if ($percent !== null && $percent >= 5.0) {
                $flags[] = new ReviewFlag(
                    'FOOD_COST_ABOVE_RECIPE',
                    'food cost, '.$this->period,
                    'Ingredients costing '.$gap->format().' more than the recipes predict left the shelf '
                        .sprintf('(%.2f%% above theoretical).', $percent),
                    [
                        'portion sizes have drifted upward from the written recipe',
                        'supplier prices rose and the recipe costing still uses the old ones',
                        'waste and preparation loss are higher than the recipes assume',
                        'stock bought at the end of the period has not been sold yet',
                    ],
                    ReviewFlag::SEVERITY_REVIEW,
                    $gap,
                );
            }
        }

        if (! $this->isComplete() && $this->lines !== []) {
            $flags[] = new ReviewFlag(
                'STOCK_COUNT_INCOMPLETE',
                'stock check, '.$this->period,
                count($this->uncountedLines()).' of '.count($this->lines)
                    .' tracked ingredients were not counted, so this period\'s stock position is '
                    .'PARTIAL and not a complete check.',
                [
                    'the count is still in progress',
                    'those ingredients are not counted every period by design',
                ],
                ReviewFlag::SEVERITY_INFO,
            );
        }

        return $flags;
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period,
            'complete' => $this->isComplete(),
            'lines' => $this->lines,
            'lines_requiring_review' => count($this->linesRequiringReview()),
            'unexplained_value' => $this->unexplainedValue()->grosze,
            'theoretical_food_cost' => $this->theoreticalFoodCost?->grosze,
            'actual_food_cost' => $this->actualFoodCost?->grosze,
            'theoretical_food_cost_percent' => $this->theoreticalFoodCostPercent(),
            'actual_food_cost_percent' => $this->actualFoodCostPercent(),
            'food_cost_gap' => $this->foodCostGap()?->grosze,
            'review_flags' => $this->reviewFlags(),
        ];
    }
}
