<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Crypto\KsefCryptography;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Http\HttpFailure;
use Poland\Ksef\Http\HttpRequest;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\KsefRetryPolicy;
use Poland\Ksef\Outgoing\KsefInvoiceSender;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Ksef\Transport\RealKsefTransport;
use Poland\Tests\Support\KsefFixtures;
use Poland\Tests\Support\ScriptedHttpClient;

/**
 * §19 and §47: every request has explicit timeouts, and a send that timed
 * out is neither called REJECTED nor sent again without asking KSeF first.
 */
final class KsefTimeoutTest extends TestCase
{
    public function test_every_request_carries_explicit_connect_and_total_timeouts(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['challenge' => 'c', 'timestamp' => '2026-09-08T08:00:00Z', 'timestampMs' => 1788000000000, 'clientIp' => '1.1.1.1']);
        $transport = new RealKsefTransport($http, KsefEnvironment::Test, 'https://api-test.ksef.mf.gov.pl/v2', ['connect' => 7, 'request' => 21, 'download' => 45, 'max_response_bytes' => 1000]);
        try {
            $transport->authChallenge();
        } catch (KsefException) {
            // the scripted body above is deliberately odd; the request itself is what matters
        }
        $request = $http->last();
        self::assertSame(7, $request->connectTimeoutSeconds);
        self::assertSame(21, $request->requestTimeoutSeconds);
        self::assertSame(1000, $request->maxResponseBytes);

        $defaults = new HttpRequest('GET', 'https://x');
        self::assertGreaterThan(0, $defaults->connectTimeoutSeconds);
        self::assertGreaterThan(0, $defaults->requestTimeoutSeconds);
    }

    public function test_a_connect_failure_has_a_known_outcome_and_may_be_retried(): void
    {
        $http = (new ScriptedHttpClient())->enqueue(new HttpFailure(HttpFailure::CONNECT, 'could not resolve host', false));
        $transport = new RealKsefTransport($http, KsefEnvironment::Test, 'https://api-test.ksef.mf.gov.pl/v2');
        try {
            $transport->sendInvoice(Secret::of('t'), 'S', 'h', 1, 'h', 1, 'c');
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::NetworkError, $e->category);
            self::assertTrue($e->outcomeKnown, 'the server never saw it');
            self::assertTrue($e->isSafeToRepeat());
        }
    }

    public function test_a_timeout_after_a_state_changing_request_has_an_unknown_outcome(): void
    {
        $http = (new ScriptedHttpClient())->enqueue(new HttpFailure(HttpFailure::TIMEOUT, 'operation timed out', true));
        $transport = new RealKsefTransport($http, KsefEnvironment::Test, 'https://api-test.ksef.mf.gov.pl/v2');
        try {
            $transport->sendInvoice(Secret::of('t'), 'S', 'h', 1, 'h', 1, 'c');
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::Timeout, $e->category);
            self::assertFalse($e->outcomeKnown);
            self::assertFalse($e->isSafeToRepeat());
            self::assertStringContainsString('NIEZNANY', $e->getMessage());
            self::assertFalse((new KsefRetryPolicy(3, 1, 5, static fn (int $s) => null))->decide($e, 1)['retry']);
        }
    }

    public function test_a_timed_out_send_is_recovered_from_the_session_invoice_list_when_it_arrived(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $audit = new InMemoryAuditSink();
        $sender = new KsefInvoiceSender($fake, $audit, static fn (int $s) => null, KsefFixtures::now());
        $session = $sender->openSession(Secret::of('access'));
        $xml = KsefFixtures::xml();

        // The request reaches KSeF (the fake records it) but the answer is lost.
        $fake->failNext('sendInvoice', static function (string $operation, array $args) use ($fake, $session): KsefException {
            // Simulate "arrived": register the invoice inside the fake before failing.
            $fake->calls[] = ['operation' => 'simulated-arrival', 'args' => []];
            $reflection = new \ReflectionProperty(FakeKsefTransport::class, 'sessions');
            $sessions = $reflection->getValue($fake);
            $sessions[$session->referenceNumber]['invoices'][] = ['reference' => 'RECOVERED-REF', 'invoiceHash' => $args['invoiceHash'], 'ordinal' => 1];
            $reflection->setValue($fake, $sessions);
            $seq = new \ReflectionProperty(FakeKsefTransport::class, 'invoiceStatusSequences');
            $sequences = $seq->getValue($fake);
            $sequences['RECOVERED-REF'] = [200];
            $seq->setValue($fake, $sequences);
            $idx = new \ReflectionProperty(FakeKsefTransport::class, 'invoiceStatusIndex');
            $indexes = $idx->getValue($fake);
            $indexes['RECOVERED-REF'] = 0;
            $idx->setValue($fake, $indexes);

            return new KsefException(KsefErrorCategory::Timeout, 'sendInvoice', 'timed out', outcomeKnown: false);
        });

        $receipt = $sender->send(Secret::of('access'), $session, $xml);
        self::assertTrue($receipt->recovered);
        self::assertSame('RECOVERED-REF', $receipt->invoiceReference);
        self::assertSame(KsefCryptography::sha256Base64($xml), $receipt->invoiceHashBase64);
        self::assertSame(1, $fake->callCount('sendInvoice'), 'NO second send');
        self::assertSame(1, $fake->callCount('sessionInvoices'));
        self::assertTrue($audit->has(KsefAuditActions::INVOICE_SUBMISSION_RECOVERED));
    }

    public function test_a_timed_out_send_that_cannot_be_found_goes_to_manual_review_and_is_never_resent(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $sender = new KsefInvoiceSender($fake, new InMemoryAuditSink(), static fn (int $s) => null, KsefFixtures::now());
        $session = $sender->openSession(Secret::of('access'));
        $fake->failNext('sendInvoice', new KsefException(KsefErrorCategory::Timeout, 'sendInvoice', 'timed out', outcomeKnown: false));

        try {
            $sender->send(Secret::of('access'), $session, KsefFixtures::xml());
            self::fail('expected an unknown-outcome failure');
        } catch (KsefException $e) {
            self::assertFalse($e->outcomeKnown);
            self::assertSame(KsefErrorCategory::Timeout, $e->category);
            self::assertStringContainsString('NIE wysyła ponownie', $e->getMessage());
            self::assertStringNotContainsStringIgnoringCase('odrzucon', $e->getMessage(), 'never REJECTED');
        }
        self::assertSame(1, $fake->callCount('sendInvoice'));
        self::assertSame(1, $fake->callCount('sessionInvoices'));
    }
}
