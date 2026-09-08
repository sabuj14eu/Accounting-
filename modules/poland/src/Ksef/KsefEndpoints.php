<?php

declare(strict_types=1);

namespace Poland\Ksef;

use RuntimeException;

/**
 * Resolves the API base URL for an environment from configuration.
 *
 * Test and demo may be overridden (a proxy, a mirror); production may not:
 * a production override is the one config edit that could send real invoices
 * to a host nobody audited, so it is refused outright.
 */
final class KsefEndpoints
{
    /** @param array<string,string|null> $baseUrls environment value => URL */
    public function __construct(
        private readonly array $baseUrls,
        private readonly ?string $override = null,
    ) {
    }

    public function baseUrl(KsefEnvironment $environment): string
    {
        $configured = trim((string) ($this->baseUrls[$environment->value] ?? ''));
        $override = trim((string) $this->override);

        if ($override !== '') {
            if ($environment->isProduction()) {
                throw new RuntimeException(
                    'KSEF_BASE_URL nie może nadpisywać adresu środowiska PRODUKCYJNEGO. '
                    .'Adres produkcyjny pochodzi wyłącznie z config/poland.php (ksef.base_urls.production).',
                );
            }
            $configured = $override;
        }

        if ($configured === '' || ! str_starts_with($configured, 'https://')) {
            throw new RuntimeException(sprintf(
                'Brak poprawnego adresu HTTPS API KSeF dla środowiska %s (ksef.base_urls.%s).',
                $environment->shortLabel(),
                $environment->value,
            ));
        }

        return rtrim($configured, '/');
    }
}
