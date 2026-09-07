<?php

declare(strict_types=1);

namespace Poland\Reconciliation;

/**
 * The states a bank transaction moves through.
 *
 *   IMPORTED → UNMATCHED → POSSIBLE MATCH → MATCHED
 *   IMPORTED → POSSIBLE DUPLICATE → EXCLUDED → MANUAL DECISION
 *
 * The original row is never deleted, in either branch. A transaction that looks
 * like a duplicate is excluded from reconciliation, not removed: the bank said
 * it happened, and deleting the record would destroy the only evidence of what
 * the statement actually contained.
 */
enum TransactionLifecycle: string
{
    case Imported = 'imported';
    case Unmatched = 'unmatched';
    case PossibleMatch = 'possible_match';
    case Matched = 'matched';

    case PossibleDuplicate = 'possible_duplicate';
    case ExcludedFromReconciliation = 'excluded';
    case ConfirmedDistinct = 'confirmed_distinct';
    case ConfirmedDuplicate = 'confirmed_duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'ZAIMPORTOWANA',
            self::Unmatched => 'NIEDOPASOWANA',
            self::PossibleMatch => 'MOŻLIWE DOPASOWANIE',
            self::Matched => 'DOPASOWANA',
            self::PossibleDuplicate => 'MOŻLIWY DUPLIKAT',
            self::ExcludedFromReconciliation => 'WYŁĄCZONA Z UZGODNIENIA',
            self::ConfirmedDistinct => 'POTWIERDZONA JAKO ODRĘBNA',
            self::ConfirmedDuplicate => 'POTWIERDZONY DUPLIKAT',
        };
    }

    /** Whether a transaction in this state counts toward the month's figures. */
    public function countsTowardTotals(): bool
    {
        return match ($this) {
            self::PossibleDuplicate,
            self::ExcludedFromReconciliation,
            self::ConfirmedDuplicate => false,
            default => true,
        };
    }

    /** Whether the row itself is preserved. Always true — nothing is deleted. */
    public function preservesSourceRecord(): bool
    {
        return true;
    }

    public function needsHumanDecision(): bool
    {
        return in_array($this, [
            self::PossibleMatch,
            self::PossibleDuplicate,
            self::ExcludedFromReconciliation,
        ], true);
    }
}
