<?php

declare(strict_types=1);

namespace Shop\Truth;

/**
 * Where a number came from — §3 of the specification.
 *
 * Every figure this application shows belongs to exactly one of these four
 * classes, and the class travels with the number rather than being written on
 * the screen next to it. A management report that says "profit 12 400 zł"
 * without saying which of these produced it is the failure mode this whole
 * application exists to avoid.
 */
enum Provenance: string
{
    /**
     * A fact taken from the official accounting system (imported one way, or
     * uploaded by the owner). This application never PRODUCES one of these; it
     * can only carry one that the accounting side produced.
     */
    case OFFICIAL_ACCOUNTING_FACT = 'OFFICIAL_ACCOUNTING_FACT';

    /** This application computed it from data it holds. Management figure only. */
    case ANALYTICAL_ESTIMATE = 'ANALYTICAL_ESTIMATE';

    /** A human typed it and stands behind it. True by assertion, not by evidence. */
    case USER_DECLARATION = 'USER_DECLARATION';

    /** A model proposed it. Never binding until a human accepts it. */
    case AI_SUGGESTION = 'AI_SUGGESTION';

    public function label(): string
    {
        return match ($this) {
            self::OFFICIAL_ACCOUNTING_FACT => 'OFFICIAL ACCOUNTING FACT',
            self::ANALYTICAL_ESTIMATE => 'ANALYTICAL ESTIMATE',
            self::USER_DECLARATION => 'USER DECLARATION',
            self::AI_SUGGESTION => 'AI SUGGESTION',
        };
    }

    public function labelPl(): string
    {
        return match ($this) {
            self::OFFICIAL_ACCOUNTING_FACT => 'FAKT KSIĘGOWY',
            self::ANALYTICAL_ESTIMATE => 'SZACUNEK ANALITYCZNY',
            self::USER_DECLARATION => 'DEKLARACJA UŻYTKOWNIKA',
            self::AI_SUGGESTION => 'SUGESTIA AI',
        };
    }

    /** Is this number allowed to stand on a screen without a human touching it? */
    public function isBinding(): bool
    {
        return $this !== self::AI_SUGGESTION;
    }

    public function isOfficial(): bool
    {
        return $this === self::OFFICIAL_ACCOUNTING_FACT;
    }

    /**
     * Rank, weakest last. Used by worst(): a total built from an official fact
     * and a user declaration is a user declaration, never an official fact.
     */
    private function rank(): int
    {
        return match ($this) {
            self::OFFICIAL_ACCOUNTING_FACT => 0,
            self::ANALYTICAL_ESTIMATE => 1,
            self::USER_DECLARATION => 2,
            self::AI_SUGGESTION => 3,
        };
    }

    /**
     * Combine provenances by WORST CASE, never by majority and never by average.
     *
     * One user-declared cash payment inside an otherwise bank-confirmed month
     * makes the month's total a figure that partly rests on somebody's word.
     * Saying so is the whole point.
     */
    public static function worst(self ...$provenances): self
    {
        if ($provenances === []) {
            throw new \InvalidArgumentException('Cannot combine an empty set of provenances.');
        }

        $worst = $provenances[0];
        foreach ($provenances as $provenance) {
            if ($provenance->rank() > $worst->rank()) {
                $worst = $provenance;
            }
        }

        return $worst;
    }
}
