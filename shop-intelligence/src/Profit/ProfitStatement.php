<?php

declare(strict_types=1);

namespace Shop\Profit;

use Shop\Costs\CostCategory;
use Shop\Costs\CostEntry;
use Shop\Truth\Certainty;
use Shop\Truth\Figure;
use Shop\Truth\Money;
use Shop\Truth\Provenance;
use Shop\Truth\ReviewFlag;
use Shop\Truth\Total;

/**
 * The management profit for a period — §11, §16, §17.
 *
 * The most dangerous number in the application, so it is the most heavily
 * labelled. It is NOT taxable income: it counts costs the tax rules would
 * disallow, it counts expected costs that have not happened, it may count
 * declared cash that no bank has seen, and it ignores depreciation and every
 * timing rule the tax code has. The label travels with the value —
 * managementProfit() returns a Total whose certainty is the worst of everything
 * that went into it, and there is no method returning the bare Money.
 */
final class ProfitStatement implements \JsonSerializable
{
    public const NOT_TAX_PROFIT =
        'MANAGEMENT FIGURE — this is not taxable income and not an accounting result. '
        .'The official figures come from the accounting system.';

    /**
     * @param  list<Figure>  $revenue
     * @param  list<CostEntry>  $costs
     */
    public function __construct(
        public readonly string $period,
        public readonly array $revenue,
        public readonly array $costs,
    ) {
    }

    public function revenueTotal(): Total
    {
        return Total::of($this->revenue);
    }

    public function costTotal(): Total
    {
        return Total::of(array_map(static fn (CostEntry $c) => $c->figure(), $this->costs));
    }

    public function costsFor(CostCategory $category): Total
    {
        return Total::of(array_map(
            static fn (CostEntry $c) => $c->figure(),
            array_values(array_filter($this->costs, static fn (CostEntry $c) => $c->category === $category)),
        ));
    }

    /** @return array<string, Total> subcategory => total */
    public function costBreakdown(CostCategory $category): array
    {
        $groups = [];
        foreach ($this->costs as $cost) {
            if ($cost->category !== $category) {
                continue;
            }
            $groups[$cost->subcategory][] = $cost->figure();
        }

        return array_map(static fn (array $figures) => Total::of($figures), $groups);
    }

    /**
     * Revenue minus every cost — as a Total, carrying its own worst case.
     *
     * @return Total
     */
    public function managementProfit(): Total
    {
        $figures = $this->revenue;

        foreach ($this->costs as $cost) {
            $figure = $cost->figure();
            $figures[] = $figure->withAmount($figure->amount->negated());
        }

        return Total::of($figures);
    }

    public function managementProfitAmount(): Money
    {
        return $this->managementProfit()->amount;
    }

    /**
     * The result built ONLY from ACTUAL evidence: bank lines, terminal and
     * platform statements, fiscal reports, invoices, counted cash.
     *
     * Declared revenue, scheduled costs and recipe estimates are left out, so
     * this is the bank's "available balance": what is known to have happened.
     * When nothing ACTUAL has been recorded it is NO DATA, not zero.
     */
    public function confirmedResult(): Total
    {
        $figures = [];

        foreach ($this->revenue as $figure) {
            if ($figure->certainty() === Certainty::ACTUAL) {
                $figures[] = $figure;
            }
        }

        foreach ($this->costs as $cost) {
            if ($cost->isActual()) {
                $figure = $cost->figure();
                $figures[] = $figure->withAmount($figure->amount->negated());
            }
        }

        return Total::of($figures);
    }

    /**
     * The result with everything included — the "book balance". Identical to
     * managementProfit(); named so that a screen showing the pair cannot
     * mislabel which is which.
     */
    public function projectedResult(): Total
    {
        return $this->managementProfit();
    }

    /** What separates the two results: everything that is not yet ACTUAL. */
    public function unconfirmedPortion(): Money
    {
        return $this->projectedResult()->amount->minus($this->confirmedResult()->amount);
    }

    public function marginPercent(): ?float
    {
        return $this->managementProfitAmount()->shareOf($this->revenueTotal()->amount);
    }

    /** The part of the result that rests on expectation rather than on events. */
    public function expectedCostsIncluded(): Money
    {
        $total = Money::zero();
        foreach ($this->costs as $cost) {
            if ($cost->isExpected()) {
                $total = $total->plus($cost->amount);
            }
        }

        return $total;
    }

    /** The part of revenue nobody but the owner has witnessed. */
    public function uncorroboratedRevenue(): Money
    {
        return $this->revenueTotal()->uncorroborated;
    }

    /**
     * The single sentence this figure may be printed with.
     *
     * §11 and §24 both demand it, and putting it in the domain rather than in a
     * template means a second template cannot forget it.
     */
    public function headline(): string
    {
        return 'Management profit '.$this->managementProfit()->describe().' · '.self::NOT_TAX_PROFIT;
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        $flags = [];

        $expected = $this->expectedCostsIncluded();
        if ($expected->isPositive()) {
            $flags[] = new ReviewFlag(
                'PROFIT_INCLUDES_EXPECTED_COSTS',
                'management profit, '.$this->period,
                $expected->format().' of the costs in this result are EXPECTED — scheduled but not yet '
                    .'confirmed by a bank line or an invoice. If they do not arrive, the profit is higher; '
                    .'if they arrive larger, it is lower.',
                [
                    'the recurring costs for this period have not been confirmed yet',
                    'the bank statement covering them has not been imported',
                ],
                ReviewFlag::SEVERITY_INFO,
                $expected,
            );
        }

        $declared = $this->uncorroboratedRevenue();
        if ($declared->isPositive()) {
            $flags[] = new ReviewFlag(
                'PROFIT_INCLUDES_UNCORROBORATED_REVENUE',
                'management profit, '.$this->period,
                $declared->format().' of the revenue in this result is recorded on a declaration or a '
                    .'cash count rather than on a bank, card-terminal or platform record.',
                [
                    'the shop takes cash and cash is counted, not confirmed by a third party',
                    'the card-terminal settlement report for the period has not been imported',
                ],
                ReviewFlag::SEVERITY_INFO,
                $declared,
            );
        }

        if ($this->revenue === []) {
            $flags[] = new ReviewFlag(
                'NO_REVENUE_RECORDED',
                'management profit, '.$this->period,
                'No revenue has been recorded for this period. That is not the same as a period with no '
                    .'sales, and this result should not be read as a loss until the sales data is in.',
                [
                    'the period is not finished',
                    'the fiscal report or the platform statements have not been entered yet',
                ],
                ReviewFlag::SEVERITY_REVIEW,
            );
        }

        return $flags;
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period,
            'revenue' => $this->revenueTotal(),
            'costs' => $this->costTotal(),
            'cost_food_and_materials' => $this->costsFor(CostCategory::FOOD_AND_MATERIALS),
            'cost_premises_and_utilities' => $this->costsFor(CostCategory::PREMISES_AND_UTILITIES),
            'cost_other_operating' => $this->costsFor(CostCategory::OTHER_OPERATING),
            'management_profit' => $this->managementProfit(),
            'confirmed_result' => $this->confirmedResult(),
            'projected_result' => $this->projectedResult(),
            'unconfirmed_portion' => $this->unconfirmedPortion()->grosze,
            'margin_percent' => $this->marginPercent(),
            'headline' => $this->headline(),
            'not_tax_profit' => self::NOT_TAX_PROFIT,
            'review_flags' => $this->reviewFlags(),
        ];
    }
}
