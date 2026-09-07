<?php

declare(strict_types=1);

namespace Poland\Domain\Enums;

/**
 * Which social-insurance regime the entrepreneur is in.
 *
 * The scheme is time-limited by law (ulga na start 6 months, preferential 24),
 * so it is stored with the month it started and the calculator checks the
 * entitlement window rather than trusting the flag alone.
 */
enum ZusScheme: string
{
    /** Ulga na start — first 6 months, health contribution only, no social. */
    case UlgaNaStart = 'ulga_na_start';

    /** Preferencyjne składki — 24 months on a base of 30% of the minimum wage. */
    case Preferential = 'preferential';

    /** Mały ZUS Plus — base derived from the previous year's income. */
    case MalyZusPlus = 'maly_zus_plus';

    /** Full contributions on 60% of the forecast average wage. */
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::UlgaNaStart => 'Ulga na start (tylko składka zdrowotna)',
            self::Preferential => 'Preferencyjne składki ZUS (24 miesiące)',
            self::MalyZusPlus => 'Mały ZUS Plus',
            self::Full => 'Pełne składki ZUS',
        };
    }

    /** Months of entitlement, or null where the scheme is open-ended. */
    public function maxMonths(): ?int
    {
        return match ($this) {
            self::UlgaNaStart => 6,
            self::Preferential => 24,
            // Mały ZUS Plus is capped at 36 months in any rolling 60, which
            // depends on the taxpayer's own history and is configured, not derived.
            self::MalyZusPlus => null,
            self::Full => null,
        };
    }

    public function paysSocial(): bool
    {
        return $this !== self::UlgaNaStart;
    }
}
