<?php

declare(strict_types=1);

namespace Shop\Truth;

/**
 * What kind of evidence stands behind a single movement of money or stock.
 *
 * Provenance and Certainty are derived FROM this, never set beside it. That is
 * the point: a caller cannot record a cash payment the owner remembered and
 * label it ACTUAL / OFFICIAL, because the label is not a parameter.
 */
enum EvidenceType: string
{
    /** A line on an imported bank statement. */
    case BANK_CONFIRMED = 'BANK_CONFIRMED';

    /** A card-terminal settlement report. */
    case CARD_TERMINAL = 'CARD_TERMINAL';

    /** A payout statement from Glovo / Uber Eats / a similar platform. */
    case PLATFORM_STATEMENT = 'PLATFORM_STATEMENT';

    /** Physical cash counted and written down, with a counter's name. */
    case CASH_COUNTED = 'CASH_COUNTED';

    /** A supplier document that was read (uploaded, or imported one way). */
    case SUPPLIER_INVOICE = 'SUPPLIER_INVOICE';

    /** A fiscal cash-register Z-report total. */
    case FISCAL_REPORT = 'FISCAL_REPORT';

    /** The owner says so. Nothing corroborates it. */
    case USER_DECLARED = 'USER_DECLARED';

    /** A standing schedule says it should happen. It has not happened yet. */
    case RECURRING_SCHEDULE = 'RECURRING_SCHEDULE';

    /** Computed from a recipe, an average, or a model. */
    case ESTIMATED = 'ESTIMATED';

    /** A model read a document and proposed this. Not binding. */
    case AI_SUGGESTED = 'AI_SUGGESTED';

    public function provenance(): Provenance
    {
        return match ($this) {
            self::BANK_CONFIRMED, self::CARD_TERMINAL, self::PLATFORM_STATEMENT,
            self::CASH_COUNTED, self::SUPPLIER_INVOICE, self::FISCAL_REPORT,
            self::RECURRING_SCHEDULE, self::ESTIMATED => Provenance::ANALYTICAL_ESTIMATE,
            self::USER_DECLARED => Provenance::USER_DECLARATION,
            self::AI_SUGGESTED => Provenance::AI_SUGGESTION,
        };
    }

    public function certainty(): Certainty
    {
        return match ($this) {
            self::BANK_CONFIRMED, self::CARD_TERMINAL, self::PLATFORM_STATEMENT,
            self::CASH_COUNTED, self::SUPPLIER_INVOICE, self::FISCAL_REPORT => Certainty::ACTUAL,
            self::USER_DECLARED => Certainty::USER_DECLARED,
            self::RECURRING_SCHEDULE => Certainty::EXPECTED,
            self::ESTIMATED, self::AI_SUGGESTED => Certainty::ESTIMATED,
        };
    }

    /**
     * Does a third party — a bank, a terminal, a platform, a supplier — also
     * say this happened?
     *
     * This is the distinction the specification's worked example turns on: an
     * invoice settled 3 000 by bank and 2 000 by declared cash is FULLY
     * ALLOCATED, and the system must never present that as "we found 5 000 in
     * the bank".
     */
    public function isIndependentlyCorroborated(): bool
    {
        return match ($this) {
            self::BANK_CONFIRMED, self::CARD_TERMINAL,
            self::PLATFORM_STATEMENT, self::SUPPLIER_INVOICE, self::FISCAL_REPORT => true,
            // Counted cash is real, but the only witness is the person counting.
            self::CASH_COUNTED, self::USER_DECLARED,
            self::RECURRING_SCHEDULE, self::ESTIMATED, self::AI_SUGGESTED => false,
        };
    }

    public function label(): string
    {
        return $this->value;
    }
}
