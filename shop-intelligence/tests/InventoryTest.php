<?php

declare(strict_types=1);

namespace Shop\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Inventory\Quantity;
use Shop\Inventory\Recipe;
use Shop\Inventory\RecipeBook;
use Shop\Inventory\StockLine;
use Shop\Inventory\StockReconciliation;
use Shop\Truth\Money;

final class InventoryTest extends TestCase
{
    private function recipeBook(): RecipeBook
    {
        return (new RecipeBook())
            ->add(new Recipe('KEBAB_L', 'Kebab duży', [
                'MEAT' => Quantity::of(180, Quantity::GRAM),
                'BREAD' => Quantity::of(1, Quantity::PIECE),
                'SAUCE' => Quantity::of(30, Quantity::MILLILITRE),
            ]))
            ->add(new Recipe('KEBAB_S', 'Kebab mały', [
                'MEAT' => Quantity::of(120, Quantity::GRAM),
                'BREAD' => Quantity::of(1, Quantity::PIECE),
                'SAUCE' => Quantity::of(20, Quantity::MILLILITRE),
            ]));
    }

    /** §8 — the arithmetic itself: recipes times portions. */
    public function test_theoretical_consumption_multiplies_recipes_by_portions(): void
    {
        $consumption = $this->recipeBook()->theoreticalConsumption(['KEBAB_L' => 400, 'KEBAB_S' => 200]);

        // 400 × 180 g + 200 × 120 g = 96 000 g
        $this->assertSame(96_000_000, $consumption->forIngredient('MEAT', Quantity::GRAM)->thousandths);
        $this->assertSame(600_000, $consumption->forIngredient('BREAD', Quantity::PIECE)->thousandths);
        $this->assertTrue($consumption->isComplete());
    }

    /** R16 — a product with no recipe consumes an UNKNOWN amount, never zero. */
    public function test_r16_sales_without_a_recipe_are_reported_not_silently_ignored(): void
    {
        $consumption = $this->recipeBook()->theoreticalConsumption([
            'KEBAB_L' => 100,
            'FRIES' => 250,
        ]);

        $this->assertFalse($consumption->isComplete());
        $this->assertSame(['FRIES' => 250], $consumption->productsWithoutRecipe);

        $flag = $consumption->reviewFlags()[0];
        $this->assertSame('SALES_WITHOUT_RECIPE', $flag->code);
        $this->assertStringContainsString('UNKNOWN, not zero', $flag->explanation);
    }

    /** R15 — §8: the difference is REQUIRES REVIEW, with the innocent readings listed first. */
    public function test_r15_a_stock_difference_requires_review_and_lists_innocent_causes(): void
    {
        $line = new StockLine(
            'MEAT',
            'Mięso kebab',
            Quantity::of(20_000, Quantity::GRAM),
            Quantity::of(100_000, Quantity::GRAM),
            Quantity::of(96_000, Quantity::GRAM),
            Quantity::of(18_000, Quantity::GRAM),
            2.0,
            Money::parse('0,03'),
        );

        // 20 000 + 100 000 − 96 000 = 24 000 expected; 18 000 counted; 6 000 g short.
        $this->assertSame(24_000_000, $line->expectedClosing()->thousandths);
        $this->assertSame(6_000_000, $line->difference()->thousandths);
        $this->assertSame(StockLine::REQUIRES_REVIEW, $line->status());
        $this->assertSame(Money::parse('180,00')->grosze, $line->financialImpact()->grosze);

        $flag = $line->reviewFlags()[0];
        $this->assertSame('STOCK_DIFFERENCE_REQUIRES_REVIEW', $flag->code);
        $this->assertStringContainsString('portions served larger', $flag->possibleExplanations[0]);
        $this->assertGreaterThanOrEqual(5, count($flag->possibleExplanations));
    }

    /** R17 — an ingredient nobody counted is NOT_COUNTED. It is not a match. */
    public function test_r17_an_uncounted_ingredient_is_not_counted_not_matched(): void
    {
        $line = new StockLine(
            'SAUCE',
            'Sos czosnkowy',
            Quantity::of(10_000, Quantity::MILLILITRE),
            Quantity::of(20_000, Quantity::MILLILITRE),
            Quantity::of(16_000, Quantity::MILLILITRE),
        );

        $this->assertSame(StockLine::NOT_COUNTED, $line->status());
        $this->assertNull($line->difference());
        $this->assertSame('STOCK_NOT_COUNTED', $line->reviewFlags()[0]->code);
    }

    /** R18 — grams are not millilitres, and the type refuses to pretend otherwise. */
    public function test_r18_quantities_of_different_units_cannot_be_combined(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not the same thing/');

        Quantity::of(100, Quantity::GRAM)->plus(Quantity::of(100, Quantity::MILLILITRE));
    }

    public function test_a_difference_inside_tolerance_is_not_escalated(): void
    {
        $line = new StockLine(
            'BREAD',
            'Bułka',
            Quantity::of(200, Quantity::PIECE),
            Quantity::of(1_000, Quantity::PIECE),
            Quantity::of(1_100, Quantity::PIECE),
            Quantity::of(90, Quantity::PIECE),
            2.0,
        );

        $this->assertSame(StockLine::WITHIN_TOLERANCE, $line->status());
        $this->assertSame([], $line->reviewFlags());
    }

    public function test_an_incomplete_stock_check_says_so(): void
    {
        $reconciliation = new StockReconciliation('2026-08', [
            new StockLine(
                'MEAT',
                'Mięso',
                Quantity::of(0, Quantity::GRAM),
                Quantity::of(1_000, Quantity::GRAM),
                Quantity::of(900, Quantity::GRAM),
                Quantity::of(100, Quantity::GRAM),
            ),
            new StockLine(
                'SAUCE',
                'Sos',
                Quantity::of(0, Quantity::MILLILITRE),
                Quantity::of(1_000, Quantity::MILLILITRE),
                Quantity::of(900, Quantity::MILLILITRE),
            ),
        ]);

        $this->assertFalse($reconciliation->isComplete());
        $codes = array_map(static fn ($f) => $f->code, $reconciliation->reviewFlags());
        $this->assertContains('STOCK_COUNT_INCOMPLETE', $codes);
    }

    public function test_the_food_cost_gap_is_reported_against_the_recipes(): void
    {
        $reconciliation = new StockReconciliation(
            '2026-08',
            [],
            Money::parse('80 000,00'),
            Money::parse('24 000,00'),
            Money::parse('27 000,00'),
        );

        $this->assertSame(30.0, $reconciliation->theoreticalFoodCostPercent());
        $this->assertSame(33.75, $reconciliation->actualFoodCostPercent());
        $this->assertSame(Money::parse('3 000,00')->grosze, $reconciliation->foodCostGap()->grosze);
        $this->assertSame('FOOD_COST_ABOVE_RECIPE', $reconciliation->reviewFlags()[0]->code);
    }
}
