<?php

declare(strict_types=1);

namespace Shop\Inventory;

use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;

/**
 * One ingredient's month — §8.
 *
 *     opening stock + purchases − theoretical consumption = expected closing
 *
 * compared with what was physically counted. The comparison has four possible
 * outcomes and only one of them is "matches"; the other three are questions,
 * not verdicts.
 */
final class StockLine implements \JsonSerializable
{
    public const MATCHED = 'MATCHED';
    public const WITHIN_TOLERANCE = 'WITHIN_TOLERANCE';
    public const REQUIRES_REVIEW = 'REQUIRES_REVIEW';
    public const NOT_COUNTED = 'NOT_COUNTED';

    public function __construct(
        public readonly string $ingredientCode,
        public readonly string $ingredientName,
        public readonly Quantity $openingStock,
        public readonly Quantity $purchases,
        public readonly Quantity $theoreticalConsumption,
        public readonly ?Quantity $physicalCount = null,
        public readonly float $tolerancePercent = 2.0,
        public readonly ?Money $unitCostPerBaseUnit = null,
        public readonly bool $consumptionIsComplete = true,
    ) {
        if ($tolerancePercent < 0) {
            throw new \InvalidArgumentException('Tolerance cannot be negative.');
        }
    }

    public function expectedClosing(): Quantity
    {
        return $this->openingStock->plus($this->purchases)->minus($this->theoreticalConsumption);
    }

    /** Positive = less on the shelf than expected. */
    public function difference(): ?Quantity
    {
        if ($this->physicalCount === null) {
            return null;
        }

        return $this->expectedClosing()->minus($this->physicalCount);
    }

    public function differencePercent(): ?float
    {
        $difference = $this->difference();
        if ($difference === null) {
            return null;
        }

        $base = $this->openingStock->plus($this->purchases);

        return $difference->absolute()->shareOf($base);
    }

    /** What the missing quantity is worth, when a unit cost is known. */
    public function financialImpact(): ?Money
    {
        $difference = $this->difference();
        if ($difference === null || $this->unitCostPerBaseUnit === null) {
            return null;
        }

        return $this->unitCostPerBaseUnit->times($difference->absolute()->toFloat());
    }

    public function status(): string
    {
        if ($this->physicalCount === null) {
            return self::NOT_COUNTED;
        }

        if ($this->difference()->isZero()) {
            return self::MATCHED;
        }

        $percent = $this->differencePercent();

        // No opening stock and no purchases: any difference at all is a
        // question, because there is no base to be a small fraction of.
        if ($percent === null) {
            return self::REQUIRES_REVIEW;
        }

        return $percent <= $this->tolerancePercent ? self::WITHIN_TOLERANCE : self::REQUIRES_REVIEW;
    }

    /**
     * The innocent readings, listed before anybody reaches for a worse one.
     *
     * §8 requires these. They are also simply the likeliest causes: in a food
     * shop, portioning drift and waste beat every other explanation combined.
     *
     * @return list<string>
     */
    public function possibleExplanations(): array
    {
        $explanations = [
            'portions served larger than the recipe assumes — the commonest cause by far',
            'waste, spillage, trimming and preparation loss that nobody recorded',
            'items given away, staff meals, or a remake after a customer complaint',
            'a delivery booked in the wrong period, or booked twice',
            'the physical count was taken at a different moment from the period end',
            'a unit-of-measure mistake somewhere between the invoice and the recipe',
            'stock moved to or from another location without a movement being recorded',
        ];

        if (! $this->consumptionIsComplete) {
            array_unshift(
                $explanations,
                'products were sold that have no recipe, so theoretical consumption is understated'
            );
        }

        return $explanations;
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        $status = $this->status();

        if ($status === self::MATCHED || $status === self::WITHIN_TOLERANCE) {
            return [];
        }

        if ($status === self::NOT_COUNTED) {
            return [new ReviewFlag(
                'STOCK_NOT_COUNTED',
                $this->ingredientName,
                'No physical count was recorded for this ingredient, so the expected closing stock of '
                    .$this->expectedClosing()->format().' has not been checked against anything.',
                [
                    'the count has not been done yet for this period',
                    'the ingredient was counted under a different code',
                    'the ingredient is not stock-tracked and does not need counting',
                ],
                ReviewFlag::SEVERITY_INFO,
            )];
        }

        $difference = $this->difference();
        $direction = $difference->isNegative() ? 'more' : 'less';
        $percent = $this->differencePercent();

        $flag = new ReviewFlag(
            'STOCK_DIFFERENCE_REQUIRES_REVIEW',
            $this->ingredientName,
            'There is '.$difference->absolute()->format().' '.$direction.' on the shelf than the '
                .'recipes and deliveries imply ('.$this->expectedClosing()->format().' expected, '
                .$this->physicalCount->format().' counted'
                .($percent === null ? '' : sprintf(', %.2f%% of what passed through', $percent)).').',
            $this->possibleExplanations(),
            ReviewFlag::SEVERITY_REVIEW,
        );

        $impact = $this->financialImpact();

        return [$impact === null ? $flag : $flag->withImpact($impact)];
    }

    public function jsonSerialize(): array
    {
        return [
            'ingredient_code' => $this->ingredientCode,
            'ingredient_name' => $this->ingredientName,
            'opening_stock' => $this->openingStock,
            'purchases' => $this->purchases,
            'theoretical_consumption' => $this->theoreticalConsumption,
            'expected_closing' => $this->expectedClosing(),
            'physical_count' => $this->physicalCount,
            'difference' => $this->difference(),
            'difference_percent' => $this->differencePercent(),
            'financial_impact' => $this->financialImpact()?->grosze,
            'status' => $this->status(),
            'review_flags' => $this->reviewFlags(),
        ];
    }
}
