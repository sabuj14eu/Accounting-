<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\SyncCursor;
use Poland\Reconciliation\TransactionLifecycle;

/**
 * The audit's §2 (cursor safety) and §7 (transaction lifecycle).
 *
 * Both protect against the same class of failure: a gap that nothing downstream
 * can detect. A skipped invoice window and a deleted bank row both produce a
 * month that is simply short, with no error anywhere.
 */
final class SyncSafetyTest extends TestCase
{
    private function through(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-31 23:59:59');
    }

    // --- §2 cursor --------------------------------------------------------

    public function test_a_complete_run_advances_the_mark_and_clears_the_page_cursor(): void
    {
        $next = SyncCursor::initial()->after($this->through(), completed: true);

        self::assertSame('2026-08-31 23:59:59', $next->syncedThrough->format('Y-m-d H:i:s'));
        self::assertNull($next->pageCursor);
        self::assertTrue($next->completed);
        self::assertFalse($next->isResumable());
    }

    public function test_a_failed_run_does_not_advance_the_mark(): void
    {
        // The whole point. Advancing after a partial run permanently skips
        // whatever was never reached, and nothing detects the gap.
        $start = SyncCursor::resume(new DateTimeImmutable('2026-07-31 23:59:59'), null);

        $next = $start->after($this->through(), completed: false, pageCursor: 'page-3');

        self::assertSame(
            '2026-07-31 23:59:59',
            $next->syncedThrough->format('Y-m-d H:i:s'),
            'the mark must stay where it was',
        );
        self::assertSame('page-3', $next->pageCursor);
        self::assertTrue($next->isResumable());
    }

    public function test_a_failed_first_run_leaves_no_mark_at_all(): void
    {
        $next = SyncCursor::initial()->after($this->through(), completed: false, pageCursor: 'page-1');

        self::assertNull($next->syncedThrough, 'nothing was ever confirmed synced');
        self::assertTrue($next->isResumable());
    }

    public function test_the_next_window_starts_from_the_last_confirmed_mark(): void
    {
        $failed = SyncCursor::resume(new DateTimeImmutable('2026-07-31 23:59:59'), null)
            ->after($this->through(), completed: false, pageCursor: 'page-3');

        // Re-requests August. Duplicates are caught by the unique index; gaps
        // are caught by nothing.
        self::assertSame(
            '2026-07-31',
            $failed->nextWindowStart(new DateTimeImmutable('2026-01-01'))->format('Y-m-d'),
        );
    }

    public function test_a_recovered_run_advances_from_where_it_stopped(): void
    {
        $failed = SyncCursor::resume(new DateTimeImmutable('2026-07-31 23:59:59'), null)
            ->after($this->through(), completed: false, pageCursor: 'page-3');

        $recovered = $failed->after($this->through(), completed: true);

        self::assertSame('2026-08-31 23:59:59', $recovered->syncedThrough->format('Y-m-d H:i:s'));
        self::assertNull($recovered->pageCursor);
        self::assertFalse($recovered->isResumable());
    }

    public function test_a_run_interrupted_twice_still_never_loses_ground(): void
    {
        $cursor = SyncCursor::resume(new DateTimeImmutable('2026-06-30 23:59:59'), null);

        foreach (['page-2', 'page-5'] as $stopped) {
            $cursor = $cursor->after($this->through(), completed: false, pageCursor: $stopped);
            self::assertSame(
                '2026-06-30 23:59:59',
                $cursor->syncedThrough->format('Y-m-d H:i:s'),
            );
        }
    }

    public function test_resuming_from_a_stored_page_cursor_is_marked_incomplete(): void
    {
        $resumed = SyncCursor::resume(new DateTimeImmutable('2026-07-31'), 'page-3');

        self::assertTrue($resumed->isResumable());
        self::assertFalse($resumed->completed);
    }

    // --- §7 lifecycle -----------------------------------------------------

    public function test_no_lifecycle_state_ever_deletes_the_source_record(): void
    {
        // The bank said it happened. Deleting the row destroys the only
        // evidence of what the statement actually contained.
        foreach (TransactionLifecycle::cases() as $state) {
            self::assertTrue(
                $state->preservesSourceRecord(),
                $state->value.' must preserve the source record',
            );
        }
    }

    public function test_duplicate_states_are_excluded_from_the_month_s_totals(): void
    {
        foreach ([
            TransactionLifecycle::PossibleDuplicate,
            TransactionLifecycle::ExcludedFromReconciliation,
            TransactionLifecycle::ConfirmedDuplicate,
        ] as $state) {
            self::assertFalse($state->countsTowardTotals(), $state->value.' must not be counted');
        }
    }

    public function test_a_transaction_confirmed_distinct_counts_again(): void
    {
        // Two genuine payments can share a date, an amount and a counterparty.
        // Once a person says so, the row counts.
        self::assertTrue(TransactionLifecycle::ConfirmedDistinct->countsTowardTotals());
    }

    public function test_matched_and_unmatched_both_count_toward_totals(): void
    {
        // An unmatched transaction is money that moved. Not knowing what it
        // paid for does not make it not have happened.
        self::assertTrue(TransactionLifecycle::Matched->countsTowardTotals());
        self::assertTrue(TransactionLifecycle::Unmatched->countsTowardTotals());
    }

    public function test_ambiguous_states_ask_for_a_person(): void
    {
        foreach ([
            TransactionLifecycle::PossibleMatch,
            TransactionLifecycle::PossibleDuplicate,
            TransactionLifecycle::ExcludedFromReconciliation,
        ] as $state) {
            self::assertTrue($state->needsHumanDecision(), $state->value.' must ask for a decision');
        }

        self::assertFalse(TransactionLifecycle::Matched->needsHumanDecision());
        self::assertFalse(TransactionLifecycle::Imported->needsHumanDecision());
    }

    public function test_both_documented_lifecycle_paths_exist_as_states(): void
    {
        // IMPORTED → UNMATCHED → POSSIBLE MATCH → MATCHED
        // IMPORTED → POSSIBLE DUPLICATE → EXCLUDED → MANUAL DECISION
        foreach ([
            ['imported', 'unmatched', 'possible_match', 'matched'],
            ['imported', 'possible_duplicate', 'excluded', 'confirmed_distinct'],
        ] as $path) {
            foreach ($path as $state) {
                self::assertInstanceOf(
                    TransactionLifecycle::class,
                    TransactionLifecycle::from($state),
                );
            }
        }
    }
}
