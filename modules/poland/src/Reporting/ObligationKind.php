<?php

declare(strict_types=1);

namespace Poland\Reporting;

/** The three things a JDG pays each month. */
enum ObligationKind: string
{
    case Zus = 'zus';
    case Pit = 'pit';
    case Vat = 'vat';

    public function label(): string
    {
        return match ($this) {
            self::Zus => 'ZUS',
            self::Pit => 'Podatek dochodowy (PIT)',
            self::Vat => 'VAT',
        };
    }

    public function payTo(): string
    {
        return match ($this) {
            self::Zus => 'Zakład Ubezpieczeń Społecznych',
            self::Pit, self::Vat => 'Urząd Skarbowy (mikrorachunek podatkowy)',
        };
    }

    public function deadlineKey(): string
    {
        return match ($this) {
            self::Zus => 'zus',
            self::Pit => 'pit_advance',
            self::Vat => 'vat',
        };
    }
}
