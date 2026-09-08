<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Incoming\IncomingSyncEngine;
use Poland\Ksef\Incoming\SyncCursorState;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Tests\Support\InMemorySyncStore;
use Poland\Tests\Support\KsefFixtures;

/**
 * §21 and §45 — the mandatory failure test:
 *
 *   page 1 persisted, committed, cursor advances
 *   page 2: API failure
 *   → page 1 stays; page 2 is not partially committed; the cursor is at the
 *     last safe position; the next sync retries page 2.
 */
final class KsefCursorSafetyTest extends TestCase
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

    /** @return array{0: FakeKsefTransport, 1: array<int,string>} */
    private function fakeWithThreeInvoices(): array
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $numbers = [];
        for ($i = 1; $i <= 3; $i++) {
            $numbers[$i] = KsefFixtures::ksefNumber($i, $this->storedAt($i));
            $fake->withInvoiceXml($numbers[$i], KsefFixtures::incomingXml('FV-'.$i, '1234567890', KsefFixtures::SELLER_NIP));
        }

        return [$fake, $numbers];
    }

    public function test_page_two_failing_at_the_api_leaves_page_one_committed_and_the_cursor_on_page_two(): void
    {
        [$fake, $n] = $this->fakeWithThreeInvoices();
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n[1], $this->storedAt(1)), KsefFixtures::metadata($n[2], $this->storedAt(2))], true, $this->hwm, 0));
        $fake->queueMetadataPage(static function (): never {
            throw new KsefException(KsefErrorCategory::ServerError, 'queryInvoiceMetadata', 'HTTP 503', 503);
        });
        $store = new InMemorySyncStore();
        $engine = new IncomingSyncEngine($fake, new FaInvoiceParser(), new InMemoryAuditSink(), 10);

        $first = $engine->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));

        self::assertFalse($first->completed);
        self::assertSame([$n[1], $n[2]], $first->imported, 'page 1 remains committed');
        self::assertCount(2, $store->records);
        self::assertCount(1, $store->commits, 'page 2 was not partially committed');
        $cursor = $store->loadCursor('Subject2');
        self::assertTrue($cursor->inProgress);
        self::assertSame(1, $cursor->pageOffset, 'the cursor remains at the last safe position: page 2');
        self::assertNull($cursor->syncedThrough, 'the completeness mark did not move');
        self::assertSame($this->hwm->format(DATE_ATOM), $cursor->windowTo?->format(DATE_ATOM));

        // Next sync retries page 2 of the same window, and only page 2.
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n[3], $this->storedAt(3))], false, $this->hwm, 1));
        $second = $engine->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));

        $queries = array_values(array_filter($fake->calls, static fn (array $c): bool => $c['operation'] === 'queryInvoiceMetadata'));
        self::assertCount(3, $queries);
        self::assertSame(1, $queries[2]['args']['pageOffset'], 'resumed at page 2');
        self::assertSame($this->hwm->format('Y-m-d\TH:i:s.u\Z'), $queries[2]['args']['query']['dateRange']['to'], 'same window end, so the offsets still mean the same rows');
        self::assertTrue($second->completed);
        self::assertSame([$n[3]], $second->imported);
        self::assertCount(3, $store->records);
        $after = $store->loadCursor('Subject2');
        self::assertFalse($after->inProgress);
        self::assertSame($this->hwm->format(DATE_ATOM), $after->syncedThrough?->format(DATE_ATOM));
    }

    public function test_a_database_failure_while_persisting_page_two_keeps_the_cursor_on_page_two(): void
    {
        [$fake, $n] = $this->fakeWithThreeInvoices();
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n[1], $this->storedAt(1))], true, $this->hwm, 0));
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n[2], $this->storedAt(2)), KsefFixtures::metadata($n[3], $this->storedAt(3))], false, $this->hwm, 1));
        $store = new InMemorySyncStore();
        $store->failOnPersist = [2];
        $engine = new IncomingSyncEngine($fake, new FaInvoiceParser(), new InMemoryAuditSink(), 10);

        $report = $engine->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));

        self::assertFalse($report->completed);
        self::assertStringContainsString('zapis strony nie powiódł się', (string) $report->stoppedBecause);
        self::assertCount(1, $store->records, 'page 2 is all-or-nothing: nothing');
        self::assertSame(1, $store->loadCursor('Subject2')->pageOffset);
        self::assertSame([$n[1]], $report->imported);
    }

    public function test_a_body_fetch_network_failure_mid_page_persists_nothing_of_that_page(): void
    {
        [$fake, $n] = $this->fakeWithThreeInvoices();
        $fake->queueMetadataPage(KsefFixtures::page([KsefFixtures::metadata($n[1], $this->storedAt(1)), KsefFixtures::metadata($n[2], $this->storedAt(2))], false, $this->hwm, 0));
        $fake->failNext('invoiceXml', new KsefException(KsefErrorCategory::Timeout, 'invoiceXml', 'timeout'));
        $store = new InMemorySyncStore();
        $report = (new IncomingSyncEngine($fake, new FaInvoiceParser(), new InMemoryAuditSink(), 10))
            ->run(Secret::of('a'), $store, KsefFixtures::SELLER_NIP, 'Subject2', new DateTimeImmutable('2026-08-01'));
        self::assertFalse($report->completed);
        self::assertSame([], $store->records);
        self::assertSame([], $store->commits);
        self::assertSame(0, $store->loadCursor('Subject2')->pageOffset);
    }

    public function test_the_cursor_value_object_only_moves_the_mark_on_completion(): void
    {
        $initial = SyncCursorState::initial();
        self::assertNull($initial->syncedThrough);
        self::assertFalse($initial->inProgress);

        $from = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $inProgress = $initial->afterPage($from, $this->hwm, 1);
        self::assertTrue($inProgress->inProgress);
        self::assertSame(1, $inProgress->pageOffset);
        self::assertNull($inProgress->syncedThrough);

        $truncated = $inProgress->afterTruncation($this->storedAt(5), $this->hwm);
        self::assertSame(0, $truncated->pageOffset);
        self::assertEquals($this->storedAt(5), $truncated->windowFrom);
        self::assertNull($truncated->syncedThrough);

        $done = $truncated->completed($this->hwm);
        self::assertFalse($done->inProgress);
        self::assertSame($this->hwm, $done->syncedThrough);
        self::assertNull($done->windowFrom);
        self::assertSame(0, $done->pageOffset);

        $this->expectException(\InvalidArgumentException::class);
        new SyncCursorState(null, null, null, -1, false);
    }

    public function test_the_legacy_window_cursor_rule_still_holds(): void
    {
        // The pre-2.0 SyncCursor is kept for the month-close compatibility path.
        $legacy = \Poland\Ksef\SyncCursor::resume(new DateTimeImmutable('2026-07-31'), null)->after(new DateTimeImmutable('2026-08-31'), completed: false, pageCursor: '3');
        self::assertSame('2026-07-31', $legacy->syncedThrough?->format('Y-m-d'));
        self::assertTrue($legacy->isResumable());
    }
}
