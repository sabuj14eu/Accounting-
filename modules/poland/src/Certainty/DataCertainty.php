<?php

declare(strict_types=1);

namespace Poland\Certainty;

/**
 * How far a figure can be trusted.
 *
 * The governing rule of this whole system: never make it look more certain than
 * the underlying data. These four states are the vocabulary for saying so, and
 * every number that reaches a screen carries one.
 */
enum DataCertainty: string
{
    /** Computed correctly from the data present. Says nothing about completeness. */
    case Calculated = 'calculated';

    /** Computed, and the inputs were reconciled against an independent source. */
    case Verified = 'verified';

    /** Something arrived or changed after this was produced. A human must look. */
    case RequiresReview = 'requires_review';

    /** A source needed for this figure is missing. The figure is not final. */
    case NotEnoughData = 'not_enough_data';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'WYLICZONE',
            self::Verified => 'ZWERYFIKOWANE',
            self::RequiresReview => 'WYMAGA PRZEGLĄDU',
            self::NotEnoughData => 'ZA MAŁO DANYCH',
        };
    }

    public function englishLabel(): string
    {
        return match ($this) {
            self::Calculated => 'CALCULATED',
            self::Verified => 'VERIFIED',
            self::RequiresReview => 'REQUIRES REVIEW',
            self::NotEnoughData => 'NOT ENOUGH DATA',
        };
    }

    /** Whether a figure in this state may be treated as final. */
    public function isFinal(): bool
    {
        return $this === self::Verified;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Verified => 0,
            self::Calculated => 1,
            self::RequiresReview => 2,
            self::NotEnoughData => 3,
        };
    }

    /**
     * Combining certainties takes the WORST, never an average.
     *
     * A report is exactly as trustworthy as its least trustworthy input, and
     * blending them would let one missing bank statement disappear behind nine
     * reconciled ones.
     */
    public static function worst(self ...$states): self
    {
        $worst = self::Verified;
        foreach ($states as $state) {
            if ($state->severity() > $worst->severity()) {
                $worst = $state;
            }
        }

        return $worst;
    }
}
