<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Incoming\IncomingSyncEngine;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\InvoiceMetadataPage;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Tests\Support\InMemorySyncStore;
use Poland\Tests\Support\KsefFixtures;

/** §23: first, middle, last, empty, duplicate, truncated pages; invalid and missing cursors; page limits. */
final class KsefPaginationTest extends TestCase
{
    private DateTimeImmutable $hwm;

    protected function setUp(): void
    {
        $this->hwm = new DateTimeImmutable('2026-09-02 00:00:00+00:00');
    }

    private function storedAt(int $n): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-09-01 00:00:00+00:00'))->modify('+'.$n.' minutes');
    }

    private function withXml(FakeKsefTransport $fake, string $number): void
    {
        $fake->withInvoiceXml($number, KsefFixtures::incomingXml('FV-'.$number, '1234567890', KsefFixtures::SELLER_NIP));
    }

    public function test_first_middle_and_last_pages_are_requested_by_increasing_offset_inside_one_window(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $numbers = [];
        for ($i = 1; $i <= 3; $i++) {
            $numbers[$i] = KsefFixtures::ksefNumber($i, $this->storedAt($i));
            $this->withXml($fake, $numbers[$i]);
        }
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($numbers[1], $this->storedAt(1))], true, $this->hwm, 0));
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($numbers[2], $this->storedAt(2))], true, $this->hwm, 1));
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($numbers[3], $this->storedAt(3))], false, $this->hwm, 2));

        $store = new InMemorySyncStore();
        $report = (new IncomingSyncEngine($fake, new FaInvoiceParser(), new InMemoryAuditSink(), 10))
            ->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));

        self::assertTrue($report->completed);
        self::assertSame(3, $report->pagesPersisted);
        self::assertSame(array_values($numbers), $report->imported);
        $offsets = array_map(static fn (array $c): int => $c['args']['pageOffset'], array_values(array_filter($fake->calls, static fn (array $c): bool => $c['operation'] === 'queryInvoiceMetadata')));
        self::assertSame([0, 1, 2], $offsets);
        // The window end is pinned to the HWM from the first page on every later page.
        $tos = array_map(static fn (array $c): ?string => $c['args']['query']['dateRange']['to'] ?? null, array_values(array_filter($fake->calls, static fn (array $c): bool => $c['operation'] === 'queryInvoiceMetadata')));
        self::assertNull($tos[0]);
        self::assertSame($this->hwm->format('Y-m-d\TH:i:s.u\Z'), $tos[1]);
        self::assertSame($tos[1], $tos[2]);
        self::assertSame([0 => 1, 1 => 2, 2 => 0], array_column(array_map(static fn (array $c): array => $c['cursor'], $store->commits), 'page_offset'));
    }

    public function test_a_duplicate_page_adds_nothing_and_still_advances(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $n = KsefFixtures::ksefNumber(9, $this->storedAt(9));
        $this->withXml($fake, $n);
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n, $this->storedAt(9))], true, $this->hwm, 0));
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n, $this->storedAt(9))], false, $this->hwm, 1));
        $store = new InMemorySyncStore();
        $report = (new IncomingSyncEngine($fake, new FaInvoiceParser(), new InMemoryAuditSink(), 10))
            ->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        self::assertSame([$n], $report->imported);
        self::assertSame([$n], $report->duplicates);
        self::assertTrue($report->completed);
        self::assertCount(1, $store->records);
    }

    public function test_a_truncated_result_opens_a_new_window_from_the_last_record(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $n1 = KsefFixtures::ksefNumber(11, $this->storedAt(11));
        $n2 = KsefFixtures::ksefNumber(12, $this->storedAt(12));
        $this->withXml($fake, $n1);
        $this->withXml($fake, $n2);
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n1, $this->storedAt(11))], true, $this->hwm, 0, truncated: true));
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n2, $this->storedAt(12))], false, $this->hwm, 0));
        $store = new InMemorySyncStore();
        $report = (new IncomingSyncEngine($fake, new FaInvoiceParser(), new InMemoryAuditSink(), 10))
            ->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        self::assertTrue($report->completed);
        $second = $fake->calls[array_keys(array_filter($fake->calls, static fn (array $c): bool => $c['operation'] === 'queryInvoiceMetadata'))[1]];
        self::assertSame(0, $second['args']['pageOffset'], 'offset reset');
        self::assertSame($this->storedAt(11)->format('Y-m-d\TH:i:s.u\Z'), $second['args']['query']['dateRange']['from'], 'window restarts at the last record');
        self::assertSame($this->storedAt(11)->format(DATE_ATOM), $store->commits[0]['cursor']['window_from']);
    }

    public function test_a_page_without_a_completeness_mark_is_a_cursor_error_and_persists_nothing(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $n = KsefFixtures::ksefNumber(13, $this->storedAt(13));
        $this->withXml($fake, $n);
        $fake->queueMetadataPage(new InvoiceMetadataPage([KsefFixtures::metadata($n, $this->storedAt(13))], false, false, null, 0, 10));
        $store = new InMemorySyncStore();
        $audit = new InMemoryAuditSink();
        $report = (new IncomingSyncEngine($fake, new FaInvoiceParser(), $audit, 10))
            ->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        self::assertFalse($report->completed);
        self::assertStringContainsString('permanentStorageHwmDate', (string) $report->stoppedBecause);
        self::assertSame([], $store->records);
        self::assertSame(KsefErrorCategory::CursorError->value, $audit->events[0]['context']['category'] ?? null);
    }

    public function test_the_page_limit_stops_the_run_but_keeps_it_resumable(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        for ($i = 21; $i <= 23; $i++) {
            $n = KsefFixtures::ksefNumber($i, $this->storedAt($i));
            $this->withXml($fake, $n);
            $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n, $this->storedAt($i))], true, $this->hwm, $i - 21));
        }
        $store = new InMemorySyncStore();
        $report = (new IncomingSyncEngine($fake, new FaInvoiceParser(), new InMemoryAuditSink(), 10))
            ->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'), maxPages: 2);
        self::assertFalse($report->completed);
        self::assertStringContainsString('limit 2 stron', (string) $report->stoppedBecause);
        self::assertSame(2, $report->pagesPersisted);
        self::assertTrue($store->loadCursor('Subject2')->inProgress);
        self::assertSame(2, $store->loadCursor('Subject2')->pageOffset);
        self::assertNull($store->loadCursor('Subject2')->syncedThrough, 'the mark moves only when the window completes');
    }

    public function test_an_invalid_query_window_is_refused_before_any_request(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Poland\Ksef\Transport\Dto\InvoiceQuery::incremental('Subject2', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-06-01'));
    }

    public function test_page_size_bounds_of_the_contract_are_enforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IncomingSyncEngine(new FakeKsefTransport(), new FaInvoiceParser(), new InMemoryAuditSink(), 5);
    }
}
