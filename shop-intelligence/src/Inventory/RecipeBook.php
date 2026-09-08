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

    /** @var array<string, list<Recipe>> product code => every superseded version, oldest first */
    private array $superseded = [];

    /**
     * Add a recipe for a product that has none yet.
     *
     * A second add() for the same product is refused rather than silently
     * replacing the first: a recipe is the model every stock difference is
     * measured against, and overwriting it rewrites last month's verdicts.
     * Use revise() and say which version is which.
     */
    public function add(Recipe $recipe): self
    {
        if ($this->has($recipe->productCode)) {
            throw new \LogicException(
                "Product {$recipe->productCode} already has a recipe. Nothing here is overwritten "
                .'silently: use revise() with a version so both versions stay readable.'
            );
        }

        $this->recipes[$recipe->productCode] = $recipe;

        return $this;
    }

    /** Replace a recipe, keeping the one it supersedes. Both must carry a version. */
    public function revise(Recipe $recipe): self
    {
        $current = $this->get($recipe->productCode);

        if ($recipe->version === null || trim($recipe->version) === '') {
            throw new \InvalidArgumentException("A revised recipe for {$recipe->productCode} must carry a version.");
        }

        if ($recipe->version === $current->version) {
            throw new \InvalidArgumentException(
                "Recipe {$recipe->productCode} version '{$recipe->version}' is already the current one."
            );
        }

        $this->superseded[$recipe->productCode][] = $current;
        $this->recipes[$recipe->productCode] = $recipe;

        return $this;
    }

    /** @return list<Recipe> superseded versions of one product, oldest first */
    public function history(string $productCode): array
    {
        return $this->superseded[$productCode] ?? [];
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
