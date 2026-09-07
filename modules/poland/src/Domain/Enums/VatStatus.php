<?php

declare(strict_types=1);

namespace Poland\Domain\Enums;

/** The taxpayer's VAT position. */
enum VatStatus: string
{
    /** Czynny podatnik VAT — charges and settles VAT. */
    case Registered = 'registered';

    /** Zwolnienie podmiotowe (art. 113) — below the annual turnover limit. */
    case ExemptBySize = 'exempt_by_size';

    /** Zwolnienie przedmiotowe (art. 43) — the activity itself is exempt. */
    case ExemptByActivity = 'exempt_by_activity';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Czynny podatnik VAT',
            self::ExemptBySize => 'Zwolnienie podmiotowe (limit obrotu)',
            self::ExemptByActivity => 'Zwolnienie przedmiotowe',
        };
    }

    public function settlesVat(): bool
    {
        return $this === self::Registered;
    }

    /**
     * Whether the turnover limit is what keeps the exemption alive.
     *
     * Only this case can lose the exemption by growing, and that transition is
     * the one the monthly report has to warn about before it happens.
     */
    public function isLimitDependent(): bool
    {
        return $this === self::ExemptBySize;
    }
}
