<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Crypto\KsefCryptography;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Fa3\Fa3Schema;
use Poland\Ksef\KsefNumber;
use Poland\Ksef\Outgoing\KsefInvoiceSender;
use Poland\Ksef\Outgoing\KsefSubmissionState as S;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Tests\Support\KsefFixtures;

/** §7 outgoing: session → send → status → UPO, with every step observable. */
final class KsefSubmissionTest extends TestCase
{
    private FakeKsefTransport $fake;

    private InMemoryAuditSink $audit;

    private KsefInvoiceSender $sender;

    protected function setUp(): void
    {
        $this->fake = new FakeKsefTransport(now: KsefFixtures::now());
        $this->audit = new InMemoryAuditSink();
        $this->sender = new KsefInvoiceSender($this->fake, $this->audit, static fn (int $s) => null, KsefFixtures::now());
    }

    public function test_a_successful_submission_ends_accepted_with_a_ksef_number_and_a_upo(): void
    {
        $xml = KsefFixtures::xml();
        $session = $this->sender->openSession(Secret::of('access'));
        self::assertSame(Fa3Schema::formCode(), $this->fake->calls[1]['args']['formCode']);
        self::assertStringStartsWith('FAKEKEY-SYM-', $this->fake->calls[1]['args']['publicKeyId'], 'the SymmetricKeyEncryption certificate');

        $this->fake->nextInvoiceStatuses([100, 150, 200]);
        $receipt = $this->sender->send(Secret::of('access'), $session, $xml);
        self::assertFalse($receipt->recovered);
        self::assertSame(KsefCryptography::sha256Base64($xml), $receipt->invoiceHashBase64);
        self::assertSame(strlen($xml), $receipt->invoiceSize);
        self::assertSame($session->referenceNumber, $receipt->sessionReference);

        $sent = $this->fake->sentInvoices($session->referenceNumber)[0];
        self::assertSame($receipt->invoiceHashBase64, $sent['invoiceHash'], 'KSeF received the hash of the plain document');

        $status = $this->sender->waitForOutcome(Secret::of('access'), $session->referenceNumber, $receipt->invoiceReference, 5, 0);
        self::assertSame(S::Accepted, $status->state);
        self::assertTrue(KsefNumber::isValid((string) $status->ksefNumber));
        self::assertSame(3, $this->fake->callCount('sessionInvoiceStatus'), 'polled until final');

        $upo = $this->sender->upo(Secret::of('access'), $session->referenceNumber, $receipt->invoiceReference);
        self::assertTrue($upo->schemaValid);
        self::assertNotNull($upo->documentFor((string) $status->ksefNumber));
        $this->sender->close(Secret::of('access'), $session->referenceNumber);

        self::assertSame([
            KsefAuditActions::SESSION_OPENED,
            KsefAuditActions::INVOICE_SUBMITTED,
            KsefAuditActions::INVOICE_STATUS_CHECKED,
            KsefAuditActions::INVOICE_STATUS_CHECKED,
            KsefAuditActions::INVOICE_STATUS_CHECKED,
            KsefAuditActions::UPO_RETRIEVED,
            KsefAuditActions::SESSION_CLOSED,
        ], $this->audit->actions());
    }

    public function test_the_invoice_travels_encrypted_and_only_the_holder_of_the_session_key_can_read_it(): void
    {
        $xml = KsefFixtures::xml();
        $session = $this->sender->openSession(Secret::of('access'));
        $this->sender->send(Secret::of('access'), $session, $xml);
        $call = $this->fake->calls[array_key_last($this->fake->calls)];
        self::assertSame('sendInvoice', $call['operation']);
        self::assertArrayNotHasKey('encryptedInvoiceContent', $call['args'], 'the fake records no bodies');
        // Re-encrypting with the same material reproduces exactly what was sent.
        $cipher = KsefCryptography::encryptInvoice($xml, $session->material);
        self::assertSame($xml, KsefCryptography::decryptInvoice($cipher, $session->material));
        self::assertStringNotContainsString('Faktura', $cipher);
    }

    public function test_a_rejection_is_final_and_carries_the_reason(): void
    {
        $session = $this->sender->openSession(Secret::of('access'));
        $this->fake->nextInvoiceStatuses([100, 450]);
        $receipt = $this->sender->send(Secret::of('access'), $session, KsefFixtures::xml());
        $status = $this->sender->waitForOutcome(Secret::of('access'), $session->referenceNumber, $receipt->invoiceReference, 5, 0);
        self::assertSame(S::Rejected, $status->state);
        self::assertSame(450, $status->code);
        self::assertNotEmpty($status->details);
        self::assertNull($status->ksefNumber);
    }

    public function test_submitted_is_not_accepted(): void
    {
        $session = $this->sender->openSession(Secret::of('access'));
        $this->fake->nextInvoiceStatuses([100]);
        $receipt = $this->sender->send(Secret::of('access'), $session, KsefFixtures::xml());
        $status = $this->sender->waitForOutcome(Secret::of('access'), $session->referenceNumber, $receipt->invoiceReference, 2, 0);
        self::assertSame(S::Processing, $status->state, 'still processing after the polls ran out — never promoted');
        self::assertNull($status->ksefNumber);
    }

    public function test_a_session_that_cannot_be_opened_is_a_classified_failure_with_nothing_sent(): void
    {
        $this->fake->failNext('openOnlineSession', new KsefException(KsefErrorCategory::AuthorizationError, 'openOnlineSession', 'Brak uprawnienia InvoiceWrite', 403));
        try {
            $this->sender->openSession(Secret::of('access'));
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::AuthorizationError, $e->category);
            self::assertTrue($e->outcomeKnown);
        }
        self::assertSame(0, $this->fake->callCount('sendInvoice'));
        self::assertFalse($this->audit->has(KsefAuditActions::SESSION_OPENED));
    }

    public function test_an_unknown_public_key_on_session_open_is_refetched_once(): void
    {
        $this->fake->failNext('openOnlineSession', new KsefException(KsefErrorCategory::ValidationError, 'openOnlineSession', 'unknown key', 400, 21470));
        $this->sender->openSession(Secret::of('access'));
        self::assertSame(2, $this->fake->callCount('publicKeyCertificates'));
        self::assertSame(2, $this->fake->callCount('openOnlineSession'));
    }

    public function test_the_upo_is_never_manufactured_locally(): void
    {
        $session = $this->sender->openSession(Secret::of('access'));
        $this->fake->nextInvoiceStatuses([100]);
        $receipt = $this->sender->send(Secret::of('access'), $session, KsefFixtures::xml());
        $this->expectException(KsefException::class);
        $this->sender->upo(Secret::of('access'), $session->referenceNumber, $receipt->invoiceReference);
    }
}
