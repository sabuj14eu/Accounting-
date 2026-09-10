<?php

declare(strict_types=1);

namespace Poland\Platforms;

/** Delivery platforms whose settlement is an accounting source. Closed list; adding one is a code change. */
enum Platform: string
{
    case Glovo = 'glovo';
    case UberEats = 'uber_eats';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Glovo => 'Glovo',
            self::UberEats => 'Uber Eats',
            self::Other => 'Inna platforma',
        };
    }

    /** The sales channel this platform's orders are recorded under. */
    public function salesChannel(): string
    {
        return match ($this) {
            self::Glovo => \Poland\Domain\SalesChannel::GLOVO,
            default => \Poland\Domain\SalesChannel::OTHER,
        };
    }
}
