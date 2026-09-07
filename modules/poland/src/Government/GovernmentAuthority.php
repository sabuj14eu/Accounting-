<?php

declare(strict_types=1);

namespace Poland\Government;

/** Who sent the letter. */
enum GovernmentAuthority: string
{
    case Zus = 'zus';
    case UrzadSkarbowy = 'urzad_skarbowy';
    case Kas = 'kas';
    case Other = 'other';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Zus => 'ZUS',
            self::UrzadSkarbowy => 'Urząd Skarbowy',
            self::Kas => 'Krajowa Administracja Skarbowa',
            self::Other => 'Inny organ',
            self::Unknown => 'Nieustalony',
        };
    }
}
