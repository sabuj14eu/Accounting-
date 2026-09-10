<?php

declare(strict_types=1);

namespace Poland\Ksef;

use Poland\Ksef\Http\KsefTransportException;

/**
 * Where the real client talks to, and as whom.
 *
 * Built from the egress registry (config/poland.php → egress.ksef), never
 * from a literal in code. Refuses an entry that is disabled, a URL that is
 * not https, or a NIP that is not ten digits — before any request is made.
 */
final class KsefEndpoint
{
    public function __construct(
        public readonly KsefEnvironment $environment,
        public readonly string $baseUrl,
        public readonly string $nip,
    ) {
        if (! str_starts_with($baseUrl, 'https://')) {
            throw KsefTransportException::config('Adres KSeF musi zaczynać się od https://.');
        }
        if (preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/', $nip) !== 1) {
            throw KsefTransportException::config('NIP podatnika do uwierzytelnienia w KSeF musi mieć 10 cyfr.');
        }
    }

    /**
     * @param array<string,mixed> $egress the `egress.ksef` config section
     */
    public static function fromEgressRegistry(array $egress, KsefEnvironment $environment, string $nip, ?string $baseUrlOverride = null): self
    {
        if (! (bool) ($egress['enabled'] ?? false)) {
            throw KsefTransportException::config(
                'Połączenie z KSeF nie jest autoryzowane w rejestrze wyjść (egress.ksef.enabled=false). '
                .'Włącz je świadomie przez KSEF_EGRESS_ENABLED=true i wpisz kto oraz kiedy zezwolił.',
            );
        }

        $url = $baseUrlOverride;
        if ($url === null || $url === '') {
            $url = (string) (($egress['base_urls'] ?? [])[$environment->value] ?? '');
        }
        if ($url === '') {
            throw KsefTransportException::config(sprintf(
                'Rejestr wyjść nie zna adresu KSeF dla środowiska "%s".',
                $environment->value,
            ));
        }

        return new self($environment, rtrim($url, '/'), $nip);
    }

    public function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    public function host(): string
    {
        return (string) parse_url($this->baseUrl, PHP_URL_HOST);
    }
}
