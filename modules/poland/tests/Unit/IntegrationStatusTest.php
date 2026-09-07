<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Certainty\IntegrationStatus;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\TransportGate;

/**
 * The audit's §22 and §20: unavailable integrations are labelled, never
 * presented as working, and nothing ever falls back silently.
 */
final class IntegrationStatusTest extends TestCase
{
    public function test_a_disconnected_ksef_says_not_connected_never_no_invoices(): void
    {
        $status = IntegrationStatus::ksef(false, 'disabled');

        self::assertSame('NOT CONNECTED', $status->status);
        self::assertFalse($status->operational);
        // The distinction the audit insists on: a claim about the system, not
        // about the taxpayer's month.
        self::assertStringContainsString('nie znaczy, że faktur nie ma', $status->detail);
        self::assertStringNotContainsString('brak faktur', mb_strtolower($status->detail));
    }

    public function test_a_fake_transport_is_never_reported_as_operational(): void
    {
        $status = IntegrationStatus::ksef(true, 'fake');

        self::assertFalse($status->operational);
        self::assertSame('TEST TRANSPORT', $status->status);
        self::assertStringContainsString('ATRAPA', $status->detail);
    }

    public function test_production_may_not_use_a_fake_transport_at_all(): void
    {
        // Not a warning — a hard error. A silent fallback would turn "no
        // connection" into "no invoices found".
        $this->expectExceptionMessageMatches('/nie może używać atrapy/u');

        (new TransportGate(KsefEnvironment::Production, true, TransportGate::FAKE))->assertUsable();
    }

    public function test_a_fake_transport_is_fine_outside_production(): void
    {
        foreach ([KsefEnvironment::Test, KsefEnvironment::Demo] as $environment) {
            (new TransportGate($environment, true, TransportGate::FAKE))->assertUsable();
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_gate_refuses_at_construction_from_config(): void
    {
        $this->expectException(\RuntimeException::class);

        TransportGate::fromConfig([
            'environment' => 'production',
            'transport_enabled' => true,
            'transport' => 'fake',
        ]);
    }

    public function test_a_transport_failure_is_never_phrased_as_an_empty_result(): void
    {
        try {
            TransportGate::reportFailure(new \RuntimeException('connection reset'));
            self::fail('reportFailure must throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('niedostępna', $e->getMessage());
            self::assertStringContainsString('NIE jest informacja o braku faktur', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'the cause is preserved for diagnosis');
        }
    }

    public function test_the_default_gate_is_disabled(): void
    {
        $gate = TransportGate::fromConfig([]);

        self::assertFalse($gate->isEnabled());
        self::assertSame('NOT CONNECTED', $gate->status()['status']);
    }

    public function test_missing_ocr_is_labelled_not_available(): void
    {
        $status = IntegrationStatus::ocr(false);

        self::assertSame('NOT AVAILABLE', $status->status);
        self::assertFalse($status->operational);
        self::assertStringContainsString('DO PRZEGLĄDU RĘCZNEGO', $status->detail);
    }

    public function test_government_submission_is_always_reported_disabled(): void
    {
        $status = IntegrationStatus::governmentSubmission();

        self::assertSame('DISABLED', $status->status);
        self::assertFalse($status->operational);
        self::assertStringContainsString('niczego nie wysyła', $status->detail);
    }

    public function test_unverified_rates_are_labelled_not_verified(): void
    {
        $status = IntegrationStatus::rates(false, 'zus_social, pit');

        self::assertSame('NOT VERIFIED', $status->status);
        self::assertStringContainsString('NIE do zapłaty podatku', $status->detail);
        self::assertStringContainsString('poland:rate-provenance', $status->nextStep);
    }

    public function test_the_panel_shows_every_integration_with_a_next_step_when_not_operational(): void
    {
        $panel = IntegrationStatus::panel(false, 'disabled', false, false, 'pit');

        self::assertCount(5, $panel);

        foreach ($panel as $status) {
            if (! $status->operational) {
                self::assertNotNull(
                    $status->nextStep,
                    $status->name.' is not operational and offers no next step',
                );
            }
        }
    }

    public function test_the_current_deployment_reports_exactly_what_is_not_working(): void
    {
        // The state this system is actually in today, asserted so a change to
        // any of it is deliberate.
        $panel = IntegrationStatus::panel(false, 'disabled', false, false);
        $byName = [];
        foreach ($panel as $status) {
            $byName[$status->name] = $status->status;
        }

        self::assertSame('NOT CONNECTED', $byName['KSeF']);
        self::assertSame('NOT AVAILABLE', $byName['OCR / odczyt tekstu']);
        self::assertSame('DISABLED', $byName['Wysyłka do organów (KSeF / JPK / pisma)']);
        self::assertSame('NOT VERIFIED', $byName['Stawki podatkowe']);
        self::assertSame('AVAILABLE', $byName['Import wyciągów bankowych']);
    }
}
