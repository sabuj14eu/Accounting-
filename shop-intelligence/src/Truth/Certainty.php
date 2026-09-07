<?php

declare(strict_types=1);

namespace Shop\Truth;

/**
 * How settled a number is — §23/§25 of the specification.
 *
 * Distinct from Provenance, which says WHO produced it. A recurring rent of
 * 4 000 zł is ACTUAL once the bank shows it left the account and EXPECTED
 * before that, and the two must never be added into one figure without the
 * split being visible. Enforced by FigureSet, not by a convention.
 */
enum Certainty: string
{
    /** It happened and there is evidence. */
    case ACTUAL = 'ACTUAL';

    /** It is scheduled or contracted but has not happened yet. */
    case EXPECTED = 'EXPECTED';

    /** Derived from a model or an average, not from a record of the event. */
    case ESTIMATED = 'ESTIMATED';

    /** Somebody says it happened; nothing corroborates it. */
    case USER_DECLARED = 'USER_DECLARED';

    public function label(): string
    {
        return $this->value;
    }

    public function labelPl(): string
    {
        return match ($this) {
            self::ACTUAL => 'RZECZYWISTE',
            self::EXPECTED => 'OCZEKIWANE',
            self::ESTIMATED => 'SZACOWANE',
            self::USER_DECLARED => 'ZADEKLAROWANE',
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::ACTUAL => 0,
            self::USER_DECLARED => 1,
            self::EXPECTED => 2,
            self::ESTIMATED => 3,
        };
    }

    public static function worst(self ...$states): self
    {
        if ($states === []) {
            throw new \InvalidArgumentException('Cannot combine an empty set of certainty states.');
        }

        $worst = $states[0];
        foreach ($states as $state) {
            if ($state->rank() > $worst->rank()) {
                $worst = $state;
            }
        }

        return $worst;
    }
}
