<?php

declare(strict_types=1);

namespace Shop\Reporting;

use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;

/**
 * "Where am I losing money?" — §21.
 *
 * Ranked by estimated financial impact, largest first, because that is the only
 * order that matches how an owner spends their limited attention. Findings with
 * no quantified impact are NOT dropped and NOT ranked at zero: they are listed
 * separately as unquantified, since a stock difference nobody has priced is not
 * a small problem, it is an unmeasured one.
 */
final class LossRanking implements \JsonSerializable
{
    /** @param list<ReviewFlag> $flags */
    public function __construct(public readonly array $flags)
    {
    }

    /**
     * Findings that are actually questions about money, ranked by how much.
     *
     * INFO-severity flags are excluded and returned by notes() instead. They
     * explain how to read a figure — "6 000 zł of this is not confirmed yet" —
     * and listing them as losses would inflate the worklist with things nobody
     * needs to investigate.
     *
     * @return list<ReviewFlag>
     */
    public function ranked(): array
    {
        $quantified = array_values(array_filter(
            $this->investigable(),
            static fn (ReviewFlag $f) => $f->financialImpact !== null && ! $f->financialImpact->isZero(),
        ));

        usort(
            $quantified,
            static fn (ReviewFlag $a, ReviewFlag $b) => $b->financialImpact->absolute()->grosze
                <=> $a->financialImpact->absolute()->grosze,
        );

        return $quantified;
    }

    /** @return list<ReviewFlag> */
    public function unquantified(): array
    {
        return array_values(array_filter(
            $this->investigable(),
            static fn (ReviewFlag $f) => $f->financialImpact === null || $f->financialImpact->isZero(),
        ));
    }

    /** Notes about how to read the numbers — not losses, and never ranked as such. */
    public function notes(): array
    {
        return array_values(array_filter(
            $this->flags,
            static fn (ReviewFlag $f) => $f->severity === ReviewFlag::SEVERITY_INFO,
        ));
    }

    /** @return list<ReviewFlag> */
    private function investigable(): array
    {
        return array_values(array_filter(
            $this->flags,
            static fn (ReviewFlag $f) => $f->severity !== ReviewFlag::SEVERITY_INFO,
        ));
    }

    /**
     * The sum of the ranked impacts.
     *
     * Explicitly NOT "money lost". Several of these overlap — a stock
     * difference and a food-cost gap can be the same kilogram of meat counted
     * twice — so the total is an upper bound on what is worth investigating,
     * not an amount anybody has lost.
     */
    public function totalEstimatedImpact(): Money
    {
        $total = Money::zero();
        foreach ($this->ranked() as $flag) {
            $total = $total->plus($flag->financialImpact->absolute());
        }

        return $total;
    }

    public function headline(): string
    {
        $ranked = $this->ranked();

        if ($ranked === [] && $this->unquantified() === []) {
            return 'Nothing found that needs investigating this period.';
        }

        return sprintf(
            '%d item%s worth investigating, up to %s in total, plus %d that could not be priced. '
            .'These may overlap — this is a worklist, not a loss.',
            count($ranked),
            count($ranked) === 1 ? '' : 's',
            $this->totalEstimatedImpact()->format(),
            count($this->unquantified()),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'headline' => $this->headline(),
            'ranked' => $this->ranked(),
            'unquantified' => $this->unquantified(),
            'notes' => $this->notes(),
            'total_estimated_impact' => $this->totalEstimatedImpact()->grosze,
        ];
    }
}
