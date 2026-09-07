<?php

declare(strict_types=1);

namespace Poland\Reporting;

/**
 * The three distinct things this system can do with a tax obligation.
 *
 * They are separated because conflating them is how software tells somebody
 * their taxes are "done" when nothing has been sent anywhere. Each stage has a
 * different truth condition, and a stage is only reached by satisfying it —
 * never by the previous stage completing successfully.
 */
enum SettlementStage: string
{
    /**
     * CALCULATION — an amount derived from the data available.
     *
     * Truth condition: the arithmetic is right given the inputs. Says nothing
     * about whether the inputs are complete or the rates are verified.
     */
    case Calculated = 'calculated';

    /**
     * PREPARATION — a document or dataset built and validated, ready to send.
     *
     * Truth condition: the document exists, validates against the current
     * schema version, and names the rule and rate versions behind it. Still
     * nothing has been sent.
     */
    case Prepared = 'prepared';

    /**
     * FILING — actually submitted to the authority, which accepted it.
     *
     * Truth condition: a submission returned a reference from the receiving
     * system. Nothing else sets this, ever.
     */
    case Filed = 'filed';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'Wyliczone — nic nie zostało wysłane',
            self::Prepared => 'Przygotowane do wysyłki — nic nie zostało wysłane',
            self::Filed => 'Złożone i potwierdzone referencją',
        };
    }

    /** Plain-language answer to "have my taxes been submitted?". */
    public function submitted(): bool
    {
        return $this === self::Filed;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Calculated => 0,
            self::Prepared => 1,
            self::Filed => 2,
        };
    }

    public function canAdvanceTo(self $next): bool
    {
        return $next->rank() === $this->rank() + 1;
    }
}
