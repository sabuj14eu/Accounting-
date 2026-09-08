<?php

declare(strict_types=1);

namespace Poland\Ksef\Health;

use DateTimeImmutable;

/**
 * Four questions that are not the same question, answered separately:
 * CONFIGURED · API_REACHABLE · AUTHENTICATED · SYNCHRONIZATION_HEALTHY.
 *
 * The overall word is derived from them by the strictest rule available:
 * "CONNECTED" requires a successful authentication that is still fresh.
 * Credentials on disk, a reachable host, a green transport flag — none of
 * those is a connection, and none of them may read as one.
 */
final class KsefHealth implements \JsonSerializable
{
    public const CONFIGURED = 'CONFIGURED';

    public const API_REACHABLE = 'API_REACHABLE';

    public const AUTHENTICATED = 'AUTHENTICATED';

    public const SYNCHRONIZATION_HEALTHY = 'SYNCHRONIZATION_HEALTHY';

    /** @param list<HealthCheck> $checks */
    private function __construct(
        public readonly array $checks,
        public readonly string $overall,
        public readonly string $mode,
    ) {
    }

    /**
     * @param array{
     *   mode: string,
     *   configured: bool,
     *   configured_detail?: string,
     *   api_reachable?: ?bool,
     *   api_checked_at?: ?DateTimeImmutable,
     *   api_detail?: ?string,
     *   authenticated?: ?bool,
     *   authenticated_at?: ?DateTimeImmutable,
     *   auth_detail?: ?string,
     *   last_sync_at?: ?DateTimeImmutable,
     *   last_sync_ok?: ?bool,
     *   sync_detail?: ?string,
     *   now: DateTimeImmutable,
     *   freshness_hours?: int
     * } $facts
     */
    public static function evaluate(array $facts): self
    {
        $now = $facts['now'];
        $freshness = $facts['freshness_hours'] ?? 24;
        $fmt = static fn (?DateTimeImmutable $d): ?string => $d?->format(DATE_ATOM);

        $configured = new HealthCheck(
            self::CONFIGURED,
            $facts['configured'] ? HealthCheck::OK : HealthCheck::FAILED,
            $facts['configured_detail'] ?? ($facts['configured'] ? 'Środowisko, NIP i token są zapisane.' : 'Brak kompletnej konfiguracji (środowisko, NIP, token).'),
        );

        $reachable = match ($facts['api_reachable'] ?? null) {
            true => new HealthCheck(self::API_REACHABLE, HealthCheck::OK, $facts['api_detail'] ?? 'API KSeF odpowiedziało.', $fmt($facts['api_checked_at'] ?? null)),
            false => new HealthCheck(self::API_REACHABLE, HealthCheck::FAILED, $facts['api_detail'] ?? 'API KSeF nie odpowiedziało.', $fmt($facts['api_checked_at'] ?? null)),
            default => new HealthCheck(self::API_REACHABLE, HealthCheck::UNKNOWN, 'Nie sprawdzono — uruchom „Testuj połączenie”.'),
        };

        $authAt = $facts['authenticated_at'] ?? null;
        $fresh = $authAt !== null && ($now->getTimestamp() - $authAt->getTimestamp()) <= $freshness * 3600;
        $authenticated = match (true) {
            ($facts['authenticated'] ?? null) === true && $fresh => new HealthCheck(self::AUTHENTICATED, HealthCheck::OK, $facts['auth_detail'] ?? 'Uwierzytelnienie tokenem KSeF powiodło się.', $fmt($authAt)),
            ($facts['authenticated'] ?? null) === true => new HealthCheck(self::AUTHENTICATED, HealthCheck::UNKNOWN, sprintf('Ostatnie udane uwierzytelnienie jest starsze niż %d h — stan nieznany, powtórz test.', $freshness), $fmt($authAt)),
            ($facts['authenticated'] ?? null) === false => new HealthCheck(self::AUTHENTICATED, HealthCheck::FAILED, $facts['auth_detail'] ?? 'Uwierzytelnienie nie powiodło się.', $fmt($authAt)),
            default => new HealthCheck(self::AUTHENTICATED, HealthCheck::UNKNOWN, 'Nie sprawdzono.'),
        };

        $sync = match ($facts['last_sync_ok'] ?? null) {
            true => new HealthCheck(self::SYNCHRONIZATION_HEALTHY, HealthCheck::OK, $facts['sync_detail'] ?? 'Ostatnia synchronizacja zakończyła się w całości.', $fmt($facts['last_sync_at'] ?? null)),
            false => new HealthCheck(self::SYNCHRONIZATION_HEALTHY, HealthCheck::FAILED, $facts['sync_detail'] ?? 'Ostatnia synchronizacja nie zakończyła się w całości.', $fmt($facts['last_sync_at'] ?? null)),
            default => new HealthCheck(self::SYNCHRONIZATION_HEALTHY, HealthCheck::UNKNOWN, 'Nie uruchomiono jeszcze synchronizacji.'),
        };

        $mode = $facts['mode'];
        $overall = match (true) {
            $mode === 'DISABLED' => 'NOT CONNECTED',
            ! $facts['configured'] => 'NOT CONFIGURED',
            $authenticated->state === HealthCheck::OK => 'CONNECTED',
            $authenticated->state === HealthCheck::FAILED, $reachable->state === HealthCheck::FAILED => 'NOT CONNECTED',
            default => 'NOT VERIFIED',
        };

        return new self([$configured, $reachable, $authenticated, $sync], $overall, $mode);
    }

    public function isConnected(): bool
    {
        return $this->overall === 'CONNECTED';
    }

    public function check(string $name): ?HealthCheck
    {
        foreach ($this->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        return null;
    }

    public function jsonSerialize(): array
    {
        return ['overall' => $this->overall, 'mode' => $this->mode, 'checks' => $this->checks];
    }
}
