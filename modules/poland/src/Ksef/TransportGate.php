<?php

declare(strict_types=1);

namespace Poland\Ksef;

use Poland\Ksef\Contracts\KsefClient;
use RuntimeException;

/**
 * Decides which KSeF transport an environment may use, and refuses to fall back.
 *
 * The failure this prevents: production loses its connection, quietly resolves
 * a fake or empty client, and reports "no invoices found" — which is a factual
 * claim about the taxpayer's month, not a status message. A failed production
 * connection must say "KSeF synchronization unavailable" and nothing else.
 *
 * So the gate is explicit, and selecting a non-production transport in
 * production is a hard error rather than a degraded mode.
 */
final class TransportGate
{
    public const REAL = 'real';

    public const FAKE = 'fake';

    public const DISABLED = 'disabled';

    public function __construct(
        private readonly KsefEnvironment $environment,
        private readonly bool $transportEnabled,
        private readonly string $transportKind = self::DISABLED,
    ) {
    }

    /**
     * @throws RuntimeException when production is asked to use a fake transport
     */
    public function assertUsable(): void
    {
        if ($this->environment->isProduction() && $this->transportKind === self::FAKE) {
            throw new RuntimeException(
                'Środowisko PRODUKCYJNE nie może używać atrapy transportu KSeF. '
                .'Ciche przejście z prawdziwego KSeF na atrapę zamieniłoby komunikat '
                .'"brak połączenia" w twierdzenie "brak faktur" — a to jest zdanie '
                .'o miesiącu podatnika, nie o systemie.',
            );
        }
    }

    public function isEnabled(): bool
    {
        return $this->transportEnabled && $this->transportKind !== self::DISABLED;
    }

    public function kind(): string
    {
        return $this->transportKind;
    }

    public function environment(): KsefEnvironment
    {
        return $this->environment;
    }

    /**
     * Status for the interface, phrased so it can never be mistaken for a
     * statement about the taxpayer's invoices.
     *
     * @return array{connected: bool, status: string, detail: string}
     */
    public function status(): array
    {
        if (! $this->transportEnabled) {
            return [
                'connected' => false,
                'status' => 'NOT CONNECTED',
                'detail' => 'Integracja KSeF jest wyłączona (KSEF_TRANSPORT_ENABLED=false). '
                    .'System nie widzi faktur — to nie znaczy, że ich nie ma.',
            ];
        }

        if ($this->transportKind === self::DISABLED) {
            return [
                'connected' => false,
                'status' => 'NOT CONNECTED',
                'detail' => 'Nie zaimplementowano transportu HTTP do KSeF. '
                    .'System nie pobiera faktur zakupowych.',
            ];
        }

        if ($this->transportKind === self::FAKE) {
            return [
                'connected' => true,
                'status' => 'TEST TRANSPORT',
                'detail' => 'Używana jest ATRAPA transportu KSeF. Dane nie pochodzą z KSeF '
                    .'i nie mogą być podstawą rozliczenia.',
            ];
        }

        return [
            'connected' => true,
            'status' => 'CONNECTED',
            'detail' => sprintf('Transport KSeF aktywny (%s).', $this->environment->label()),
        ];
    }

    /**
     * Wrap a transport failure so it can never be read as "no invoices".
     *
     * @throws RuntimeException always
     */
    public static function reportFailure(\Throwable $cause): never
    {
        throw new RuntimeException(
            'Synchronizacja z KSeF niedostępna: '.$cause->getMessage()
            .'. To NIE jest informacja o braku faktur — system nie zdołał ich sprawdzić.',
            0,
            $cause,
        );
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): self
    {
        $environment = KsefEnvironment::tryFrom((string) ($config['environment'] ?? 'test'))
            ?? KsefEnvironment::Test;

        $gate = new self(
            $environment,
            (bool) ($config['transport_enabled'] ?? false),
            (string) ($config['transport'] ?? self::DISABLED),
        );

        $gate->assertUsable();

        return $gate;
    }
}
