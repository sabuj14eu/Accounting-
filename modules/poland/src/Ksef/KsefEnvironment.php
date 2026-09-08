<?php

declare(strict_types=1);

namespace Poland\Ksef;

/**
 * Which KSeF instance to talk to.
 *
 * Base URLs are configuration (config/poland.php, `ksef.base_urls`), never
 * constants in code: the Ministry has moved them before and a hard-coded host
 * becomes a silent outage. `Production` is separate from `Demo` and `Test` so
 * a token issued for one can never be sent to another, and so the transport
 * gate can refuse a fake transport there.
 */
enum KsefEnvironment: string
{
    case Test = 'test';
    case Demo = 'demo';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Środowisko testowe KSeF (TEST)',
            self::Demo => 'Środowisko przedprodukcyjne KSeF (DEMO)',
            self::Production => 'KSeF PRODUKCYJNY — dane rzeczywiste',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Test => 'TEST',
            self::Demo => 'DEMO',
            self::Production => 'PRODUCTION',
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /** The Ministry's API documentation for this environment (pinned in resources/ksef/PINNED.md). */
    public function documentationUrl(): string
    {
        return match ($this) {
            self::Test => 'https://api-test.ksef.mf.gov.pl/docs/v2',
            self::Demo => 'https://api-demo.ksef.mf.gov.pl/docs/v2',
            self::Production => 'https://api.ksef.mf.gov.pl/docs/v2',
        };
    }

    /** Where the taxpayer manages permissions and tokens for this environment. */
    public function taxpayerApplicationUrl(): string
    {
        return match ($this) {
            self::Test => 'https://ap-test.ksef.mf.gov.pl/web/',
            self::Demo => 'https://ap-demo.ksef.mf.gov.pl/web/',
            self::Production => 'https://ap.ksef.mf.gov.pl/web/',
        };
    }
}
