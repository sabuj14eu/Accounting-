<?php

declare(strict_types=1);

namespace Shop\Profit;

/**
 * Where the money came in — §10.
 *
 * Kept as a closed set because per-channel margin is the point of Page 3, and a
 * free-text channel is a channel that quietly stops being compared.
 */
enum RevenueChannel: string
{
    case DIRECT_CASH = 'DIRECT_CASH';
    case DIRECT_CARD = 'DIRECT_CARD';
    case GLOVO = 'GLOVO';
    case UBER_EATS = 'UBER_EATS';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT_CASH => 'In shop — cash',
            self::DIRECT_CARD => 'In shop — card',
            self::GLOVO => 'Glovo',
            self::UBER_EATS => 'Uber Eats',
            self::OTHER => 'Other',
        };
    }

    public function isPlatform(): bool
    {
        return $this === self::GLOVO || $this === self::UBER_EATS;
    }
}
