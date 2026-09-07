<?php

declare(strict_types=1);

namespace Poland\Certainty;

/**
 * How far a figure can be trusted.
 *
 * The governing rule of this whole system: never make it look more certain than
 * the underlying data. These four states are the vocabulary for saying so, and
 * every number that reaches a screen carries one.
 */
enum DataCertainty: string
{
    /** Computed correctly from the data present. Says nothing about completeness. */
    case Calculated = 'calculated';

    /** Computed, and the inputs were reconciled against an independent source. */
    case Verified = 'verified';

    /** Something arrived or changed after this was produced. A human must look. */
    case RequiresReview = 'requires_review';

    /** A source needed for this figure is missing. The figure is not final. */
    case NotEnoughData = 'not_enough_data';

    /**
     * A required production dependency is unavailable or unverified, so the
     * work was never attempted.
     *
     * Distinct from NOT ENOUGH DATA: that means a source the taxpayer could
     * supply is absent, and they can fix it by uploading a statement. BLOCKED
     * means the system itself is not permitted or not able to proceed —
     * unverified rates, a disabled transport — and no amount of uploading
     * changes it.
     */
    case Blocked = 'blocked';

    /**
     * An operation was attempted and technically failed.
     *
     * Distinct from BLOCKED: something ran and broke, so there is an error to
     * diagnose and a retry that might work. Collapsing the two would send
     * somebody hunting for a missing document when the real problem is a
     * timeout, or the reverse.
     */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'WYLICZONE',
            self::Verified => 'ZWERYFIKOWANE',
            self::RequiresReview => 'WYMAGA PRZEGLĄDU',
            self::NotEnoughData => 'ZA MAŁO DANYCH',
            self::Blocked => 'ZABLOKOWANE',
            self::Failed => 'BŁĄD OPERACJI',
        };
    }

    public function englishLabel(): string
    {
        return match ($this) {
            self::Calculated => 'CALCULATED',
            self::Verified => 'VERIFIED',
            self::RequiresReview => 'REQUIRES REVIEW',
            self::NotEnoughData => 'NOT ENOUGH DATA',
            self::Blocked => 'BLOCKED',
            self::Failed => 'FAILED',
        };
    }

    /** Whether a figure in this state may be treated as final. */
    public function isFinal(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Whether the taxpayer can improve this by supplying something.
     *
     * NOT ENOUGH DATA is actionable — upload the statement. BLOCKED and FAILED
     * are not: they need an operator or a verification step, and telling a
     * taxpayer to "add missing data" would send them looking for a document
     * that does not exist.
     */
    public function isUserActionable(): bool
    {
        return in_array($this, [self::NotEnoughData, self::RequiresReview], true);
    }

    /** Whether the system, rather than the data, is what is wrong. */
    public function isSystemCondition(): bool
    {
        return in_array($this, [self::Blocked, self::Failed], true);
    }

    public function severity(): int
    {
        return match ($this) {
            self::Verified => 0,
            self::Calculated => 1,
            self::RequiresReview => 2,
            self::NotEnoughData => 3,
            // A dependency that is unavailable or unverified outranks missing
            // data: the taxpayer can supply a statement, but cannot unblock an
            // unverified rate table by uploading anything.
            self::Blocked => 4,
            self::Failed => 5,
        };
    }

    /**
     * Combining certainties takes the WORST, never an average.
     *
     * A report is exactly as trustworthy as its least trustworthy input, and
     * blending them would let one missing bank statement disappear behind nine
     * reconciled ones.
     */
    public static function worst(self ...$states): self
    {
        $worst = self::Verified;
        foreach ($states as $state) {
            if ($state->severity() > $worst->severity()) {
                $worst = $state;
            }
        }

        return $worst;
    }
}
