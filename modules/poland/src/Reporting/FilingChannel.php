<?php

declare(strict_types=1);

namespace Poland\Reporting;

/**
 * Where a prepared document goes. Each is a separate authority with its own
 * schema, its own transport and its own idea of what a reference number is.
 *
 * Listed as an enum rather than a free string so that a settlement can never
 * be marked filed to "somewhere".
 */
enum FilingChannel: string
{
    /** JPK_V7M / JPK_V7K to the Ministry of Finance. */
    case JpkV7 = 'jpk_v7';

    /** Structured invoices to the Krajowy System e-Faktur. */
    case Ksef = 'ksef';

    /** ZUS DRA and payment. */
    case Zus = 'zus';

    /** Annual PIT return (PIT-36, PIT-36L, PIT-28). */
    case PitReturn = 'pit_return';

    /**
     * The taxpayer did it themselves outside this system and recorded the
     * reference here. Honest, and common — a JDG often pays ZUS by bank
     * transfer and files through e-Deklaracje.
     */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::JpkV7 => 'JPK_V7 (Ministerstwo Finansów)',
            self::Ksef => 'KSeF — Krajowy System e-Faktur',
            self::Zus => 'ZUS (DRA / płatność)',
            self::PitReturn => 'Roczne zeznanie PIT',
            self::Manual => 'Złożone poza systemem (zarejestrowane ręcznie)',
        };
    }

    /**
     * Whether this system can perform the submission itself.
     *
     * All false today. When a channel becomes automated, this flips — and the
     * UI stops offering "mark as filed by hand" for it. Until then the honest
     * answer is that the taxpayer files and records the reference.
     */
    public function automated(): bool
    {
        return false;
    }
}
