<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Incoming\IncomingSyncEngine;
use Poland\Ksef\Outgoing\KsefInvoiceSender;
use Poland\Ksef\Outgoing\KsefSubmissionState as S;
use Poland\Ksef\Outgoing\SubmissionIdentity;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Tests\Support\InMemorySyncStore;
use Poland\Tests\Support\KsefFixtures;

/** §17, §46: the same invoice twice yields one identity, one record, and a complete history. */
final class KsefIdempotencyTest extends TestCase
{
    public function test_the_submission_identity_mirrors_ksefs_duplicate_rule_and_separates_environments(): void
    {
        $a = SubmissionIdentity::activeKey('test', '526-587-76-35', 'VAT', 'FV/2026/09/001');
        self::assertSame($a, SubmissionIdentity::activeKey('TEST', '5265877635', 'vat', ' FV/2026/09/001 '));
        self::assertNotSame($a, SubmissionIdentity::activeKey('production', '5265877635', 'VAT', 'FV/2026/09/001'));
        self::assertNotSame($a, SubmissionIdentity::activeKey('test', '5265877635', 'KOR', 'FV/2026/09/001'));
        self::assertNotSame($a, SubmissionIdentity::activeKey('test', '5265877635', 'VAT', 'FV/2026/09/002'));
        self::assertSame(64, strlen($a));
        self::assertSame(hash('sha256', 'x'), SubmissionIdentity::documentKey('x'));
    }

    public function test_sending_the_same_invoice_twice_is_reported_by_ksef_as_a_duplicate_and_goes_to_review(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $sender = new KsefInvoiceSender($fake, new InMemoryAuditSink(), static fn (int $s) => null, KsefFixtures::now());
        $xml = KsefFixtures::xml();

        $s1 = $sender->openSession(Secret::of('a'));
        $fake->nextInvoiceStatuses([200]);
        $r1 = $sender->send(Secret::of('a'), $s1, $xml);
        $first = $sender->status(Secret::of('a'), $s1->referenceNumber, $r1->invoiceReference);
        self::assertSame(S::Accepted, $first->state);

        $s2 = $sender->openSession(Secret::of('a'));
        $fake->nextInvoiceStatuses([440]);
        $r2 = $sender->send(Secret::of('a'), $s2, $xml);
        $second = $sender->status(Secret::of('a'), $s2->referenceNumber, $r2->invoiceReference);
        self::assertSame(S::ManualReview, $second->state, 'never a second acceptance, never a silent success');
        self::assertNull($second->ksefNumber);
        self::assertArrayHasKey('originalKsefNumber', $second->extensions);
    }

    public function test_retrieving_the_same_incoming_invoice_twice_stores_one_record_and_fetches_its_body_once(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $store = new InMemorySyncStore();
        $storedAt = new DateTimeImmutable('2026-09-01 08:00:00+00:00');
        $hwm = new DateTimeImmutable('2026-09-02 00:00:00+00:00');
        $number = KsefFixtures::ksefNumber(1, $storedAt);
        $meta = KsefFixtures::metadata($number, $storedAt);
        $fake->withInvoiceXml($number, KsefFixtures::incomingXml('FV-1', '1234567890', KsefFixtures::SELLER_NIP));

        // Two runs; KSeF returns the same invoice both times (the (HWM, now] overlap the guide describes).
        $fake->queueMetadataPage(KsefFixtures::page([$meta], false, $hwm));
        $fake->queueMetadataPage(KsefFixtures::page([$meta], false, $hwm->modify('+1 day')));
        $engine = new IncomingSyncEngine($fake, new FaInvoiceParser(), new InMemoryAuditSink(), 10);
        $first = $engine->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        $second = $engine->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));

        self::assertSame([$number], $first->imported);
        self::assertSame([], $second->imported);
        self::assertSame([$number], $second->duplicates);
        self::assertCount(1, $store->records);
        self::assertSame(1, $fake->callCount('invoiceXml'), 'no second body fetch for a known number');
        self::assertCount(2, $store->commits, 'both runs are recorded; the second advanced the mark');
    }

    public function test_a_page_persisted_twice_does_not_double_a_record(): void
    {
        $store = new InMemorySyncStore();
        $storedAt = new DateTimeImmutable('2026-09-01 08:00:00+00:00');
        $number = KsefFixtures::ksefNumber(5, $storedAt);
        $record = new \Poland\Ksef\Incoming\IncomingInvoiceRecord(KsefFixtures::metadata($number, $storedAt), 'Subject2', 'incoming', null, null, true, 'x');
        $cursor = \Poland\Ksef\Incoming\SyncCursorState::initial()->afterPage($storedAt, $storedAt, 1);
        self::assertSame(1, $store->persistPage('Subject2', [$record], $cursor));
        self::assertSame(0, $store->persistPage('Subject2', [$record, $record], $cursor));
        self::assertCount(1, $store->records);
    }
}
