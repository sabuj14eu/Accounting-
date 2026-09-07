<?php

declare(strict_types=1);

namespace Poland\Ksef;

/**
 * Which KSeF instance to talk to.
 *
 * Base URLs are configuration, never constants in code: the Ministry has moved
 * them before and a hard-coded host becomes a silent outage. `Production` is
 * separate from `Demo` and `Test` so a token issued for one can never be sent
 * to another.
 */
enum KsefEnvironment: string
{
    case Test = 'test';
    case Demo = 'demo';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Środowisko testowe KSeF',
            self::Demo => 'Środowisko demo KSeF',
            self::Production => 'KSeF PRODUKCYJNY — dane rzeczywiste',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }
}
