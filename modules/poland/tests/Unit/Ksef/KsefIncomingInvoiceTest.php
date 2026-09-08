<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Audit\KsefAuditActions;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Incoming\IncomingInvoiceRecord;
use Poland\Ksef\Incoming\IncomingSyncEngine;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\InvoiceQuery;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Tests\Support\InMemorySyncStore;
use Poland\Tests\Support\KsefFixtures;

/** §20, §22: incoming invoices are persisted with their XML; a failure is a failure, never an empty month. */
final class KsefIncomingInvoiceTest extends TestCase
{
    private DateTimeImmutable $storedAt;

    private DateTimeImmutable $hwm;

    protected function setUp(): void
    {
        $this->storedAt = new DateTimeImmutable('2026-09-01 08:00:00+00:00');
        $this->hwm = new DateTimeImmutable('2026-09-02 00:00:00+00:00');
    }

    private function engine(FakeKsefTransport $fake, InMemoryAuditSink $audit): IncomingSyncEngine
    {
        return new IncomingSyncEngine($fake, new FaInvoiceParser(), $audit, 10);
    }

    public function test_a_first_sync_persists_metadata_xml_and_parse_result(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $audit = new InMemoryAuditSink();
        $store = new InMemorySyncStore();
        $n1 = KsefFixtures::ksefNumber(1, $this->storedAt);
        $n2 = KsefFixtures::ksefNumber(2, $this->storedAt->modify('+1 hour'), KsefFixtures::SELLER_NIP);
        $fake->withInvoiceXml($n1, KsefFixtures::incomingXml('FV-1', '1234567890', KsefFixtures::SELLER_NIP));
        $fake->withInvoiceXml($n2, KsefFixtures::incomingXml('FV-2', KsefFixtures::SELLER_NIP, '1234567890'));
        $fake->queueMetadataPage(KsefFixtures::page([
            KsefFixtures::metadata($n1, $this->storedAt),
            KsefFixtures::metadata($n2, $this->storedAt->modify('+1 hour'), KsefFixtures::SELLER_NIP, '1234567890'),
        ], false, $this->hwm));

        $report = $this->engine($fake, $audit)->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, InvoiceQuery::SUBJECT_BUYER, new DateTimeImmutable('2026-08-01'));

        self::assertTrue($report->completed);
        self::assertTrue($report->isClean());
        self::assertSame([$n1, $n2], $report->imported);
        self::assertSame(IncomingInvoiceRecord::DIRECTION_INCOMING, $store->records[$n1]->direction);
        self::assertSame(IncomingInvoiceRecord::DIRECTION_OUTGOING, $store->records[$n2]->direction, 'our NIP as seller = outgoing, whatever the query role');
        self::assertNotNull($store->records[$n1]->xml);
        self::assertSame('FV-1', $store->records[$n1]->parsed?->metadata->invoiceNumber);
        self::assertFalse($store->records[$n1]->needsReview);
        self::assertSame($this->hwm->format(DATE_ATOM), $report->cursorAfter->syncedThrough?->format(DATE_ATOM));

        $query = $fake->calls[0]['args']['query'];
        self::assertSame('PermanentStorage', $query['dateRange']['dateType']);
        self::assertTrue($query['dateRange']['restrictToPermanentStorageHwmDate']);
        self::assertSame('Asc', $fake->calls[0]['args']['sortOrder']);
        self::assertTrue($audit->has(KsefAuditActions::INCOMING_PAGE_PERSISTED));
        self::assertTrue($audit->has(KsefAuditActions::INCOMING_SYNCED));
    }

    public function test_a_correction_and_a_hash_mismatch_are_flagged_for_review_but_still_stored(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $store = new InMemorySyncStore();
        $kor = KsefFixtures::ksefNumber(3, $this->storedAt);
        $bad = KsefFixtures::ksefNumber(4, $this->storedAt);
        $fake->withInvoiceXml($kor, KsefFixtures::incomingXml('KOR-1', '1234567890', KsefFixtures::SELLER_NIP, 'KOR'));
        $fake->withInvoiceXml($bad, KsefFixtures::incomingXml('FV-4', '1234567890', KsefFixtures::SELLER_NIP));
        $fake->queueMetadataPage(KsefFixtures::page([
            KsefFixtures::metadata($kor, $this->storedAt, type: 'Kor'),
            KsefFixtures::metadata($bad, $this->storedAt, xmlHash: base64_encode(hash('sha256', 'something else', true))),
        ], false, $this->hwm));

        $report = $this->engine($fake, new InMemoryAuditSink())->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        self::assertCount(2, $report->needsReview);
        self::assertStringContainsString('korygująca', $report->needsReview[$kor]);
        self::assertStringContainsString('Skrót SHA-256', $report->needsReview[$bad]);
        self::assertCount(2, $store->records);
    }

    public function test_a_body_that_ksef_refuses_to_serve_is_stored_without_xml_and_marked(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $store = new InMemorySyncStore();
        $n = KsefFixtures::ksefNumber(6, $this->storedAt);
        // No XML registered in the fake → 21406 (validation, not retryable).
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n, $this->storedAt)], false, $this->hwm));
        $report = $this->engine($fake, new InMemoryAuditSink())->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        self::assertTrue($report->completed);
        self::assertNull($store->records[$n]->xml);
        self::assertTrue($store->records[$n]->needsReview);
        self::assertStringContainsString('Nie pobrano treści', (string) $store->records[$n]->reviewReason);
    }

    public function test_a_transport_failure_is_never_read_as_no_invoices(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $fake->failNext('queryInvoiceMetadata', new KsefException(KsefErrorCategory::NetworkError, 'queryInvoiceMetadata', 'connection reset'));
        $store = new InMemorySyncStore();
        $audit = new InMemoryAuditSink();
        $report = $this->engine($fake, $audit)->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        self::assertFalse($report->completed);
        self::assertFalse($report->isClean());
        self::assertStringContainsString('connection reset', (string) $report->stoppedBecause);
        self::assertStringContainsString('nic nie zostało pominięte', $report->summary());
        self::assertSame([], $store->commits);
        self::assertNull($report->cursorAfter->syncedThrough);
        self::assertTrue($audit->has(KsefAuditActions::SYNC_FAILED));
    }

    public function test_an_honest_empty_page_completes_and_advances_to_the_hwm(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $store = new InMemorySyncStore();
        $fake->queueMetadataPage(KsefFixtures::page([], false, $this->hwm));
        $report = $this->engine($fake, new InMemoryAuditSink())->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        self::assertTrue($report->completed);
        self::assertSame([], $report->imported);
        self::assertSame($this->hwm->format(DATE_ATOM), $store->loadCursor('Subject2')->syncedThrough?->format(DATE_ATOM));
    }
}
