<?php

declare(strict_types=1);

namespace Shop\Monitoring;

use Shop\Truth\Money;

/**
 * What "normal" looks like for one series, and whether there is enough of it.
 *
 * Three months is not a trend. The baseline refuses to have an opinion below a
 * floor, and every monitor asks it first, so no alert can be raised off two
 * data points and a slow Tuesday.
 */
final class Baseline
{
    public const MINIMUM_PERIODS = 3;

    /** @param array<string, Money> $history period => value, oldest first */
    public function __construct(public readonly array $history)
    {
    }

    public function hasEnoughHistory(): bool
    {
        return count($this->history) >= self::MINIMUM_PERIODS;
    }

    /** Median, which a single closed month or a freak week cannot drag around. */
    public function median(): ?Money
    {
        if (! $this->hasEnoughHistory()) {
            return null;
        }

        $values = array_map(static fn (Money $m) => $m->grosze, array_values($this->history));
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return Money::grosze(
            $count % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2)
        );
    }

    /** Signed percentage change of $actual against the median. Null when unknown. */
    public function deviationPercent(Money $actual): ?float
    {
        $median = $this->median();

        if ($median === null || $median->isZero()) {
            return null;
        }

        return round(($actual->grosze - $median->grosze) / abs($median->grosze) * 100, 2);
    }
}
