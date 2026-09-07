<?php

declare(strict_types=1);

namespace Shop\Cash;

use Shop\Truth\EvidenceType;
use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;
use Shop\Truth\Total;

/**
 * The cash drawer as a ledger — §18.
 *
 * Opening balance, every movement in date order with a running balance after
 * each one, and an expected closing balance to compare against what was
 * physically counted. This is the shape a bank statement has, for the reason a
 * bank statement has it: a single closing figure tells you that something is
 * wrong, and only a running balance tells you when it went wrong.
 *
 * When the count differs the ledger says REQUIRES REVIEW and lists what causes
 * that in a shop. It does not say who. ReviewFlag will not let it.
 */
final class CashLedger implements \JsonSerializable
{
    /** @var list<CashMovement> */
    private array $movements = [];

    public readonly Money $tolerance;

    public function __construct(
        public readonly string $period,
        public readonly Money $openingBalance,
        public readonly ?Money $physicalCount = null,
        public readonly ?string $countedBy = null,
        ?Money $tolerance = null,
    ) {
        $tolerance ??= Money::zero();

        if ($tolerance->isNegative()) {
            throw new \InvalidArgumentException('Tolerance cannot be negative.');
        }

        if ($physicalCount !== null && trim((string) $countedBy) === '') {
            throw new \InvalidArgumentException(
                'A cash count must name who counted it. An anonymous count cannot be questioned, '
                .'corrected or defended.'
            );
        }

        $this->tolerance = $tolerance;
    }

    public function record(CashMovement $movement): self
    {
        $this->movements[] = $movement;

        return $this;
    }

    /** @return list<CashMovement> */
    public function movements(): array
    {
        $sorted = $this->movements;
        usort($sorted, static fn (CashMovement $a, CashMovement $b) => $a->date <=> $b->date);

        return $sorted;
    }

    /**
     * Every movement with the balance after it — the statement view.
     *
     * @return list<array{movement: CashMovement, balance: Money}>
     */
    public function statement(): array
    {
        $balance = $this->openingBalance;
        $rows = [];

        foreach ($this->movements() as $movement) {
            $balance = $balance->plus($movement->amount);
            $rows[] = ['movement' => $movement, 'balance' => $balance];
        }

        return $rows;
    }

    public function expectedClosing(): Money
    {
        $balance = $this->openingBalance;
        foreach ($this->movements as $movement) {
            $balance = $balance->plus($movement->amount);
        }

        return $balance;
    }

    public function movementTotal(): Total
    {
        return Total::of(array_map(static fn (CashMovement $m) => $m->figure(), $this->movements));
    }

    /** Positive = less in the drawer than the ledger expects. */
    public function difference(): ?Money
    {
        if ($this->physicalCount === null) {
            return null;
        }

        return $this->expectedClosing()->minus($this->physicalCount);
    }

    public function status(): string
    {
        if ($this->physicalCount === null) {
            return 'NOT_COUNTED';
        }

        return $this->difference()->absolute()->greaterThan($this->tolerance)
            ? 'REQUIRES_REVIEW'
            : 'RECONCILED';
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        $status = $this->status();

        if ($status === 'RECONCILED') {
            return [];
        }

        if ($status === 'NOT_COUNTED') {
            return [new ReviewFlag(
                'CASH_NOT_COUNTED',
                'cash, '.$this->period,
                'The drawer has not been counted for this period, so the expected balance of '
                    .$this->expectedClosing()->format().' has not been checked against anything.',
                [
                    'the count for this period has not been done yet',
                    'the shop banks its takings daily and does not hold a float worth counting',
                ],
                ReviewFlag::SEVERITY_INFO,
            )];
        }

        $difference = $this->difference();
        $direction = $difference->isPositive() ? 'less' : 'more';

        return [new ReviewFlag(
            'CASH_DIFFERENCE_REQUIRES_REVIEW',
            'cash, '.$this->period,
            'There is '.$difference->absolute()->format().' '.$direction.' in the drawer than the ledger '
                .'expects ('.$this->expectedClosing()->format().' expected, '
                .$this->physicalCount->format().' counted'
                .($this->countedBy === null ? '' : ' by '.$this->countedBy).').',
            [
                'change given wrongly during a busy service',
                'a cash sale rung up on the till but not entered here, or entered twice',
                'a supplier or a repair paid out of the drawer with no note kept',
                'the float was topped up or banked without being recorded',
                'the count was taken before the last service of the period finished',
                'a refund to a customer paid in cash and not recorded',
            ],
            ReviewFlag::SEVERITY_REVIEW,
            $difference->absolute(),
        )];
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period,
            'opening_balance' => $this->openingBalance->grosze,
            'movements' => $this->movements(),
            'statement' => array_map(
                static fn (array $row) => [
                    'movement' => $row['movement'],
                    'balance' => $row['balance']->grosze,
                ],
                $this->statement(),
            ),
            'expected_closing' => $this->expectedClosing()->grosze,
            'physical_count' => $this->physicalCount?->grosze,
            'counted_by' => $this->countedBy,
            'difference' => $this->difference()?->grosze,
            'status' => $this->status(),
            'review_flags' => $this->reviewFlags(),
        ];
    }
}
