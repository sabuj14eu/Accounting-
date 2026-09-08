<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Auth\KsefAuthenticator;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Incoming\IncomingSyncEngine;
use Poland\Ksef\Outgoing\KsefInvoiceSender;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Tests\Support\InMemorySyncStore;
use Poland\Tests\Support\KsefFixtures;

/** §26: every significant action leaves an event; none carries a secret; failures say "failed". */
final class KsefAuditTest extends TestCase
{
    public function test_the_whole_outgoing_path_is_recorded_in_order(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $audit = new InMemoryAuditSink();
        $token = Secret::of('ksef-token-value-should-never-appear');
        $context = (new KsefAuthenticator($fake, $audit, 5, 0, static fn (int $s) => null, KsefFixtures::now()))->authenticate($token, KsefFixtures::SELLER_NIP);
        $sender = new KsefInvoiceSender($fake, $audit, static fn (int $s) => null, KsefFixtures::now());
        $session = $sender->openSession($context->bearer());
        $fake->nextInvoiceStatuses([200]);
        $receipt = $sender->send($context->bearer(), $session, KsefFixtures::xml());
        $sender->status($context->bearer(), $session->referenceNumber, $receipt->invoiceReference);
        $sender->upo($context->bearer(), $session->referenceNumber, $receipt->invoiceReference);
        $sender->close($context->bearer(), $session->referenceNumber);

        self::assertSame([
            KsefAuditActions::AUTH_SUCCEEDED,
            KsefAuditActions::SESSION_OPENED,
            KsefAuditActions::INVOICE_SUBMITTED,
            KsefAuditActions::INVOICE_STATUS_CHECKED,
            KsefAuditActions::UPO_RETRIEVED,
            KsefAuditActions::SESSION_CLOSED,
        ], $audit->actions());

        $dump = $audit->dump();
        self::assertStringNotContainsString('ksef-token-value', $dump);
        self::assertStringNotContainsString($context->accessToken->token->reveal(), $dump);
        self::assertStringNotContainsString($session->material->aesKey->reveal(), $dump);
        self::assertStringContainsString($session->referenceNumber, $dump);
        self::assertStringContainsString($receipt->invoiceReference, $dump);
        self::assertStringContainsString('"environment":"test"', $dump);
        foreach ($audit->events as $event) {
            self::assertSame('ok', $event['result']);
        }
    }

    public function test_failures_are_recorded_as_failed_with_their_category(): void
    {
        $fake = (new FakeKsefTransport(now: KsefFixtures::now()))->authStatusSequence([450]);
        $audit = new InMemoryAuditSink();
        try {
            (new KsefAuthenticator($fake, $audit, 3, 0, static fn (int $s) => null, KsefFixtures::now()))->authenticate(Secret::of('bad'), KsefFixtures::SELLER_NIP);
        } catch (KsefException) {
        }
        self::assertSame([KsefAuditActions::AUTH_FAILED], $audit->actions());
        self::assertSame('failed', $audit->events[0]['result']);
        self::assertSame(KsefErrorCategory::AuthenticationError->value, $audit->events[0]['context']['category']);
        self::assertSame(450, $audit->events[0]['context']['ksef_code']);
        self::assertNotNull($audit->events[0]['error']);
    }

    public function test_a_sync_records_each_page_with_its_cursor_and_the_run_as_a_whole(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $audit = new InMemoryAuditSink();
        $storedAt = new DateTimeImmutable('2026-09-01 08:00:00+00:00');
        $n = KsefFixtures::ksefNumber(1, $storedAt);
        $fake->withInvoiceXml($n, KsefFixtures::incomingXml('FV-1', '1234567890', KsefFixtures::SELLER_NIP));
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n, $storedAt)], false, new DateTimeImmutable('2026-09-02 00:00:00+00:00')));
        (new IncomingSyncEngine($fake, new FaInvoiceParser(), $audit, 10))->run(Secret::of('a'), new InMemorySyncStore(), KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));

        self::assertSame([KsefAuditActions::INCOMING_PAGE_PERSISTED, KsefAuditActions::INCOMING_SYNCED], $audit->actions());
        self::assertSame(0, $audit->events[0]['context']['page_offset']);
        self::assertArrayHasKey('cursor_after', $audit->events[0]['context']);
        self::assertSame('ok', $audit->events[1]['result']);
        self::assertSame(1, count($audit->events[1]['context']['imported']));
    }

    public function test_the_audit_vocabulary_covers_the_specification(): void
    {
        $reflection = new \ReflectionClass(KsefAuditActions::class);
        $actions = array_values($reflection->getConstants());
        foreach ([
            'ksef.connection_tested', 'ksef.auth.succeeded', 'ksef.auth.failed', 'ksef.session.opened',
            'ksef.invoice.xml_generated', 'ksef.invoice.xml_validation_passed', 'ksef.invoice.xml_validation_failed',
            'ksef.invoice.submitted', 'ksef.invoice.accepted', 'ksef.invoice.rejected', 'ksef.invoice.status_checked',
            'ksef.invoice.upo_retrieved', 'ksef.incoming.synced', 'ksef.sync.failed', 'ksef.retry_scheduled', 'ksef.manual_review_required',
        ] as $required) {
            self::assertContains($required, $actions, $required);
        }
    }
}
