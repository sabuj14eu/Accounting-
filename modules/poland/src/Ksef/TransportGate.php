<?php

declare(strict_types=1);

namespace Poland\Ksef;

use RuntimeException;

/**
 * Decides which KSeF transport an environment may use, and refuses to fall back.
 *
 * The failure this prevents: production loses its connection, quietly resolves
 * a fake or empty client, and reports "no invoices found" — which is a factual
 * claim about the taxpayer's month, not a status message. A failed production
 * connection must say "KSeF synchronization unavailable" and nothing else.
 *
 * Five modes, stated explicitly so a screen can never merge two of them:
 * DISABLED · FAKE · TEST · DEMO · PRODUCTION. FAKE in production is a hard
 * error at construction; DISABLED in production is the intended initial state.
 */
final class TransportGate
{
    public const REAL = 'real';

    public const FAKE = 'fake';

    public const DISABLED = 'disabled';

    public const MODE_DISABLED = 'DISABLED';

    public const MODE_FAKE = 'FAKE';

    public const MODE_TEST = 'TEST';

    public const MODE_DEMO = 'DEMO';

    public const MODE_PRODUCTION = 'PRODUCTION';

    public function __construct(
        private readonly KsefEnvironment $environment,
        private readonly bool $transportEnabled,
        private readonly string $transportKind = self::DISABLED,
    ) {
        if (! in_array($transportKind, [self::REAL, self::FAKE, self::DISABLED], true)) {
            throw new RuntimeException(sprintf(
                'Nieznany rodzaj transportu KSeF "%s". Dozwolone: real, fake, disabled.',
                $transportKind,
            ));
        }
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

    public function isFake(): bool
    {
        return $this->isEnabled() && $this->transportKind === self::FAKE;
    }

    public function isReal(): bool
    {
        return $this->isEnabled() && $this->transportKind === self::REAL;
    }

    public function kind(): string
    {
        return $this->transportKind;
    }

    public function environment(): KsefEnvironment
    {
        return $this->environment;
    }

    /** DISABLED · FAKE · TEST · DEMO · PRODUCTION */
    public function mode(): string
    {
        if (! $this->isEnabled()) {
            return self::MODE_DISABLED;
        }
        if ($this->transportKind === self::FAKE) {
            return self::MODE_FAKE;
        }

        return match ($this->environment) {
            KsefEnvironment::Test => self::MODE_TEST,
            KsefEnvironment::Demo => self::MODE_DEMO,
            KsefEnvironment::Production => self::MODE_PRODUCTION,
        };
    }

    /**
     * Whether real invoices with legal effect can leave this system.
     * Only the PRODUCTION mode; TEST and DEMO carry no legal effect and
     * must never receive real taxpayer data.
     */
    public function hasLegalEffect(): bool
    {
        return $this->mode() === self::MODE_PRODUCTION;
    }

    /**
     * Status for the interface, phrased so it can never be mistaken for a
     * statement about the taxpayer's invoices.
     *
     * @return array{connected: bool, status: string, detail: string, mode: string}
     */
    public function status(): array
    {
        $mode = $this->mode();

        if (! $this->transportEnabled) {
            return [
                'connected' => false,
                'status' => 'NOT CONNECTED',
                'mode' => $mode,
                'detail' => 'Integracja KSeF jest wyłączona (KSEF_TRANSPORT_ENABLED=false). '
                    .'System nie widzi faktur — to nie znaczy, że ich nie ma.',
            ];
        }

        if ($this->transportKind === self::DISABLED) {
            return [
                'connected' => false,
                'status' => 'NOT CONNECTED',
                'mode' => $mode,
                'detail' => 'Transport KSeF ustawiony na "disabled" (KSEF_TRANSPORT). '
                    .'System nie pobiera ani nie wysyła faktur.',
            ];
        }

        if ($this->transportKind === self::FAKE) {
            return [
                'connected' => true,
                'status' => 'TEST TRANSPORT',
                'mode' => $mode,
                'detail' => 'Używana jest ATRAPA transportu KSeF. Dane nie pochodzą z KSeF '
                    .'i nie mogą być podstawą rozliczenia.',
            ];
        }

        return [
            'connected' => true,
            'status' => 'CONNECTED',
            'mode' => $mode,
            'detail' => sprintf('Transport KSeF skonfigurowany (%s). Połączenie potwierdza dopiero udany test uwierzytelnienia.', $this->environment->label()),
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
        $raw = (string) ($config['environment'] ?? 'test');
        $environment = KsefEnvironment::tryFrom($raw);
        if ($environment === null) {
            throw new RuntimeException(sprintf(
                'Nieznane środowisko KSeF "%s" (KSEF_ENVIRONMENT). Dozwolone: test, demo, production.',
                $raw,
            ));
        }

        $gate = new self(
            $environment,
            (bool) ($config['transport_enabled'] ?? false),
            (string) ($config['transport'] ?? self::DISABLED),
        );

        $gate->assertUsable();

        return $gate;
    }
}
