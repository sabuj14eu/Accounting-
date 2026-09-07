<?php

declare(strict_types=1);

namespace Poland\Rates;

use Poland\Domain\Period;
use RuntimeException;

/**
 * Thrown when no rate version covers the requested settlement period.
 *
 * This is deliberately fatal rather than a fallback to the newest version.
 * Carrying last year's ZUS base into a new January produces a number that is
 * wrong, plausible, and internally consistent — the worst kind. The engine
 * refuses to settle a month it has no rates for.
 */
final class MissingRateException extends RuntimeException
{
    public static function for(string $table, Period $period): self
    {
        return new self(sprintf(
            'No "%s" rate version covers %s. Add the version to config/rates/%s.php '
            .'(with its source) before settling that period — the engine will not '
            .'extrapolate rates it was not given.',
            $table,
            $period->toString(),
            $table,
        ));
    }
}
