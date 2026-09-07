<?php

declare(strict_types=1);

namespace Shop\Inventory;

/**
 * Every recipe, and the honest answer for products that have none.
 *
 * A product sold with no recipe does not consume zero ingredients. It consumes
 * an unknown amount, and the difference between those two readings is the whole
 * §8 calculation. So the book reports UNCOVERED products rather than quietly
 * leaving them out of the sum.
 */
final class RecipeBook
{
    /** @var array<string, Recipe> */
    private array $recipes = [];

    public function add(Recipe $recipe): self
    {
        $this->recipes[$recipe->productCode] = $recipe;

        return $this;
    }

    public function has(string $productCode): bool
    {
        return isset($this->recipes[$productCode]);
    }

    public function get(string $productCode): Recipe
    {
        return $this->recipes[$productCode]
            ?? throw new \OutOfBoundsException("No recipe for product '{$productCode}'.");
    }

    /**
     * Theoretical consumption for a period's sales mix.
     *
     * @param  array<string, int>  $salesMix  product code => portions sold
     */
    public function theoreticalConsumption(array $salesMix): TheoreticalConsumption
    {
        $totals = [];
        $uncovered = [];

        foreach ($salesMix as $productCode => $portions) {
            if (! $this->has($productCode)) {
                $uncovered[$productCode] = $portions;

                continue;
            }

            foreach ($this->get($productCode)->forPortions($portions) as $ingredient => $quantity) {
                $totals[$ingredient] = isset($totals[$ingredient])
                    ? $totals[$ingredient]->plus($quantity)
                    : $quantity;
            }
        }

        return new TheoreticalConsumption($totals, $uncovered);
    }
}
