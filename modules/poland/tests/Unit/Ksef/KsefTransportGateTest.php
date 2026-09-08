<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\KsefEndpoints;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\Transport\DisabledKsefTransport;
use Poland\Ksef\Transport\KsefTransport;
use Poland\Ksef\TransportGate;
use Poland\Laravel\Support\Ksef\KsefTransportFactory;

/** §7 and §48: five explicit modes, and production can never run on a fake. */
final class KsefTransportGateTest extends TestCase
{
    public function test_the_five_modes_are_distinct(): void
    {
        self::assertSame('DISABLED', (new TransportGate(KsefEnvironment::Test, false, 'real'))->mode());
        self::assertSame('DISABLED', (new TransportGate(KsefEnvironment::Test, true, 'disabled'))->mode());
        self::assertSame('FAKE', (new TransportGate(KsefEnvironment::Test, true, 'fake'))->mode());
        self::assertSame('TEST', (new TransportGate(KsefEnvironment::Test, true, 'real'))->mode());
        self::assertSame('DEMO', (new TransportGate(KsefEnvironment::Demo, true, 'real'))->mode());
        self::assertSame('PRODUCTION', (new TransportGate(KsefEnvironment::Production, true, 'real'))->mode());
    }

    public function test_only_production_has_legal_effect(): void
    {
        self::assertTrue((new TransportGate(KsefEnvironment::Production, true, 'real'))->hasLegalEffect());
        self::assertFalse((new TransportGate(KsefEnvironment::Demo, true, 'real'))->hasLegalEffect());
        self::assertFalse((new TransportGate(KsefEnvironment::Test, true, 'real'))->hasLegalEffect());
        self::assertFalse((new TransportGate(KsefEnvironment::Production, false, 'real'))->hasLegalEffect());
    }

    public function test_production_with_fake_transport_is_refused_at_construction_from_config(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nie może używać atrapy/u');
        TransportGate::fromConfig(['environment' => 'production', 'transport_enabled' => true, 'transport' => 'fake']);
    }

    public function test_production_with_fake_is_refused_even_when_transport_is_not_enabled(): void
    {
        // The flag being off is not a reason to tolerate the misconfiguration.
        $this->expectException(\RuntimeException::class);
        TransportGate::fromConfig(['environment' => 'production', 'transport_enabled' => false, 'transport' => 'fake']);
    }

    public function test_production_with_disabled_transport_stays_disabled(): void
    {
        $gate = TransportGate::fromConfig(['environment' => 'production', 'transport_enabled' => false, 'transport' => 'disabled']);
        self::assertSame('DISABLED', $gate->mode());
        self::assertFalse($gate->isEnabled());
        self::assertSame('NOT CONNECTED', $gate->status()['status']);

        $gate = TransportGate::fromConfig(['environment' => 'production', 'transport_enabled' => true, 'transport' => 'disabled']);
        self::assertSame('DISABLED', $gate->mode());
    }

    public function test_an_unknown_transport_kind_or_environment_is_refused(): void
    {
        try {
            TransportGate::fromConfig(['environment' => 'staging', 'transport' => 'real', 'transport_enabled' => true]);
            self::fail('unknown environment accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('staging', $e->getMessage());
        }
        try {
            new TransportGate(KsefEnvironment::Test, true, 'mock');
            self::fail('unknown transport accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('mock', $e->getMessage());
        }
    }

    public function test_the_default_configuration_is_disabled_test(): void
    {
        $gate = TransportGate::fromConfig([]);
        self::assertSame('DISABLED', $gate->mode());
        self::assertSame(KsefEnvironment::Test, $gate->environment());
    }

    public function test_the_status_text_never_makes_a_claim_about_invoices(): void
    {
        foreach ([[false, 'real'], [true, 'disabled'], [true, 'fake'], [true, 'real']] as [$enabled, $kind]) {
            $status = (new TransportGate(KsefEnvironment::Test, $enabled, $kind))->status();
            self::assertStringNotContainsStringIgnoringCase('brak faktur', str_replace('nie znaczy, że ich nie ma', '', $status['detail']));
            self::assertStringNotContainsStringIgnoringCase('no invoices', $status['detail']);
        }
        // A real transport that is merely configured is not a verified connection.
        self::assertStringContainsString('potwierdza dopiero udany test', (new TransportGate(KsefEnvironment::Test, true, 'real'))->status()['detail']);
    }

    public function test_the_factory_binds_disabled_fake_or_real_through_the_gate(): void
    {
        $disabled = (new KsefTransportFactory(['environment' => 'test', 'transport' => 'disabled', 'transport_enabled' => true]))->transport();
        self::assertInstanceOf(DisabledKsefTransport::class, $disabled);

        $fake = (new KsefTransportFactory(['environment' => 'demo', 'transport' => 'fake', 'transport_enabled' => true]))->transport();
        self::assertSame(KsefTransport::KIND_FAKE, $fake->kind());
        self::assertSame(KsefEnvironment::Demo, $fake->environment());

        $real = (new KsefTransportFactory([
            'environment' => 'test', 'transport' => 'real', 'transport_enabled' => true,
            'base_urls' => ['test' => 'https://api-test.ksef.mf.gov.pl/v2'],
        ]))->transport();
        self::assertSame(KsefTransport::KIND_REAL, $real->kind());

        $this->expectException(\RuntimeException::class);
        (new KsefTransportFactory(['environment' => 'production', 'transport' => 'fake', 'transport_enabled' => true]))->transport();
    }

    public function test_production_base_url_cannot_be_overridden(): void
    {
        $endpoints = new KsefEndpoints(['test' => 'https://api-test.ksef.mf.gov.pl/v2', 'production' => 'https://api.ksef.mf.gov.pl/v2'], 'https://evil.example/v2');
        self::assertSame('https://evil.example/v2', $endpoints->baseUrl(KsefEnvironment::Test), 'test may be redirected to a mirror');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PRODUKCYJNEGO/u');
        $endpoints->baseUrl(KsefEnvironment::Production);
    }

    public function test_a_non_https_base_url_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new KsefEndpoints(['test' => 'http://api-test.ksef.mf.gov.pl/v2']))->baseUrl(KsefEnvironment::Test);
    }
}
