<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Health\HealthCheck;
use Poland\Ksef\Health\KsefHealth;

/** §36, §37: four separate questions, and CONNECTED only from a fresh, real authentication. */
final class KsefIntegrationStatusTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-08 12:00:00');
    }

    public function test_credentials_alone_are_not_a_connection(): void
    {
        $health = KsefHealth::evaluate(['mode' => 'TEST', 'configured' => true, 'now' => $this->now]);
        self::assertSame('NOT VERIFIED', $health->overall);
        self::assertSame(HealthCheck::OK, $health->check(KsefHealth::CONFIGURED)?->state);
        self::assertSame(HealthCheck::UNKNOWN, $health->check(KsefHealth::AUTHENTICATED)?->state);
        self::assertFalse($health->isConnected());
    }

    public function test_reachable_but_authentication_failed_is_not_connected(): void
    {
        $health = KsefHealth::evaluate([
            'mode' => 'TEST', 'configured' => true, 'now' => $this->now,
            'api_reachable' => true, 'api_checked_at' => $this->now,
            'authenticated' => false, 'authenticated_at' => $this->now, 'auth_detail' => 'Nieprawidłowy token',
        ]);
        self::assertSame('NOT CONNECTED', $health->overall);
        self::assertSame(HealthCheck::OK, $health->check(KsefHealth::API_REACHABLE)?->state);
        self::assertSame(HealthCheck::FAILED, $health->check(KsefHealth::AUTHENTICATED)?->state);
    }

    public function test_a_fresh_successful_authentication_is_connected_and_a_stale_one_is_not(): void
    {
        $fresh = KsefHealth::evaluate(['mode' => 'TEST', 'configured' => true, 'now' => $this->now, 'api_reachable' => true, 'authenticated' => true, 'authenticated_at' => $this->now->modify('-2 hours'), 'freshness_hours' => 24]);
        self::assertSame('CONNECTED', $fresh->overall);
        self::assertTrue($fresh->isConnected());

        $stale = KsefHealth::evaluate(['mode' => 'TEST', 'configured' => true, 'now' => $this->now, 'api_reachable' => true, 'authenticated' => true, 'authenticated_at' => $this->now->modify('-30 hours'), 'freshness_hours' => 24]);
        self::assertSame('NOT VERIFIED', $stale->overall);
        self::assertSame(HealthCheck::UNKNOWN, $stale->check(KsefHealth::AUTHENTICATED)?->state);
        self::assertStringContainsString('starsze niż 24 h', (string) $stale->check(KsefHealth::AUTHENTICATED)?->detail);
    }

    public function test_a_disabled_transport_is_not_connected_whatever_else_is_true(): void
    {
        $health = KsefHealth::evaluate(['mode' => 'DISABLED', 'configured' => true, 'now' => $this->now, 'api_reachable' => true, 'authenticated' => true, 'authenticated_at' => $this->now]);
        self::assertSame('NOT CONNECTED', $health->overall);
    }

    public function test_missing_configuration_is_named(): void
    {
        $health = KsefHealth::evaluate(['mode' => 'TEST', 'configured' => false, 'configured_detail' => 'Brakuje: token KSeF', 'now' => $this->now]);
        self::assertSame('NOT CONFIGURED', $health->overall);
        self::assertStringContainsString('token KSeF', (string) $health->check(KsefHealth::CONFIGURED)?->detail);
    }

    public function test_synchronisation_health_is_a_separate_question(): void
    {
        $health = KsefHealth::evaluate(['mode' => 'TEST', 'configured' => true, 'now' => $this->now, 'api_reachable' => true, 'authenticated' => true, 'authenticated_at' => $this->now, 'last_sync_ok' => false, 'last_sync_at' => $this->now, 'sync_detail' => 'przerwana na stronie 2']);
        self::assertSame('CONNECTED', $health->overall, 'a broken sync does not make the connection a lie');
        self::assertSame(HealthCheck::FAILED, $health->check(KsefHealth::SYNCHRONIZATION_HEALTHY)?->state);
        self::assertStringContainsString('stronie 2', (string) $health->check(KsefHealth::SYNCHRONIZATION_HEALTHY)?->detail);
    }

    public function test_the_panel_serialises_with_its_mode(): void
    {
        $json = json_decode((string) json_encode(KsefHealth::evaluate(['mode' => 'DEMO', 'configured' => true, 'now' => $this->now])), true);
        self::assertSame('DEMO', $json['mode']);
        self::assertCount(4, $json['checks']);
        self::assertSame(['CONFIGURED', 'API_REACHABLE', 'AUTHENTICATED', 'SYNCHRONIZATION_HEALTHY'], array_column($json['checks'], 'name'));
    }
}
