<?php

declare(strict_types=1);

namespace Shop\Inventory;

use Shop\Truth\ReviewFlag;

/**
 * What the recipes say should have been used — and what they could not say.
 */
final class TheoreticalConsumption implements \JsonSerializable
{
    /**
     * @param  array<string, Quantity>  $byIngredient
     * @param  array<string, int>  $productsWithoutRecipe  product code => portions sold
     */
    public function __construct(
        public readonly array $byIngredient,
        public readonly array $productsWithoutRecipe = [],
    ) {
    }

    public function isComplete(): bool
    {
        return $this->productsWithoutRecipe === [];
    }

    public function forIngredient(string $code, string $unit): Quantity
    {
        return $this->byIngredient[$code] ?? Quantity::zero($unit);
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        if ($this->isComplete()) {
            return [];
        }

        $portions = array_sum($this->productsWithoutRecipe);
        $names = implode(', ', array_keys($this->productsWithoutRecipe));

        return [new ReviewFlag(
            'SALES_WITHOUT_RECIPE',
            'theoretical consumption',
            "{$portions} portions were sold of products with no recipe ({$names}). Their ingredient "
            .'use is UNKNOWN, not zero, so every stock difference below is understated by whatever '
            .'they consumed.',
            [
                'the product is new and its recipe has not been entered yet',
                'the product is bought ready-made and consumes no tracked ingredient',
                'the product code on the till differs from the code in the recipe book',
            ],
            ReviewFlag::SEVERITY_REVIEW,
        )];
    }

    public function jsonSerialize(): array
    {
        return [
            'by_ingredient' => $this->byIngredient,
            'complete' => $this->isComplete(),
            'products_without_recipe' => $this->productsWithoutRecipe,
            'review_flags' => $this->reviewFlags(),
        ];
    }
}
