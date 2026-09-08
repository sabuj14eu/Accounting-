<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\DisabledKsefTransport;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Ksef\TransportGate;
use Poland\Laravel\Support\Ksef\KsefTransportFactory;

/** §29, §48: production stays OFF, a fake can never run there, and a disabled transport refuses everything. */
final class KsefProductionSafetyTest extends TestCase
{
    public function test_production_plus_fake_refuses_to_operate(): void
    {
        $this->expectException(\RuntimeException::class);
        new KsefTransportFactory(['environment' => 'production', 'transport' => 'fake', 'transport_enabled' => true])->gate();
    }

    public function test_production_plus_disabled_remains_disabled_and_every_call_refuses(): void
    {
        $factory = new KsefTransportFactory(['environment' => 'production', 'transport' => 'disabled', 'transport_enabled' => false]);
        self::assertSame('DISABLED', $factory->gate()->mode());
        $transport = $factory->transport();
        self::assertInstanceOf(DisabledKsefTransport::class, $transport);
        self::assertSame(KsefEnvironment::Production, $transport->environment());

        foreach ([
            fn () => $transport->publicKeyCertificates(),
            fn () => $transport->authChallenge(),
            fn () => $transport->queryInvoiceMetadata(Secret::of('x'), \Poland\Ksef\Transport\Dto\InvoiceQuery::incremental('Subject2', new \DateTimeImmutable('2026-09-01')), 0, 10),
            fn () => $transport->invoiceXml(Secret::of('x'), '5265877635-20250826-0100001AF629-AF'),
            fn () => $transport->sendInvoice(Secret::of('x'), 's', 'h', 1, 'h', 1, 'c'),
        ] as $call) {
            try {
                $call();
                self::fail('disabled transport returned instead of refusing');
            } catch (KsefException $e) {
                self::assertSame(KsefErrorCategory::IntegrationDisabled, $e->category);
                self::assertStringContainsString('NIE jest informacja o braku faktur', $e->getMessage());
            }
        }
    }

    public function test_a_fake_transport_cannot_be_injected_into_a_production_factory(): void
    {
        $factory = new KsefTransportFactory(['environment' => 'production', 'transport' => 'disabled', 'transport_enabled' => false]);
        $this->expectException(\RuntimeException::class);
        $factory->useTransport(new FakeKsefTransport(KsefEnvironment::Production));
    }

    public function test_the_shipped_env_example_and_installer_default_to_off(): void
    {
        $root = dirname(__DIR__, 5);
        $env = (string) file_get_contents($root.'/.env.example');
        self::assertMatchesRegularExpression('/^KSEF_TRANSPORT_ENABLED=false$/m', $env);
        self::assertMatchesRegularExpression('/^KSEF_TRANSPORT=disabled$/m', $env);
        self::assertMatchesRegularExpression('/^KSEF_TOKEN'.'=$/m', $env, 'no token value may ship');

        $installer = (string) file_get_contents($root.'/bin/deploy-contabo.sh');
        self::assertStringContainsString('set_env KSEF_TRANSPORT_ENABLED false', $installer);
        self::assertStringContainsString('set_env KSEF_TRANSPORT disabled', $installer);
    }

    public function test_the_production_gate_document_exists_and_lists_the_activation_config(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 5).'/docs/KSEF_PRODUCTION_GATE.md');
        self::assertStringContainsString('KSEF_ENVIRONMENT=production', $doc);
        self::assertStringContainsString('KSEF_TRANSPORT=real', $doc);
        self::assertStringContainsString('KSEF_TRANSPORT_ENABLED=true', $doc);
        self::assertStringContainsString('NOT REACHED', $doc);
    }

    public function test_test_and_demo_are_explicitly_without_legal_effect(): void
    {
        foreach ([KsefEnvironment::Test, KsefEnvironment::Demo] as $environment) {
            self::assertFalse((new TransportGate($environment, true, 'real'))->hasLegalEffect());
            self::assertStringNotContainsString('rzeczywiste', $environment->label());
        }
        self::assertStringContainsString('rzeczywiste', KsefEnvironment::Production->label());
    }
}
