<?php

declare(strict_types=1);

namespace Poland\Banking;

use DateTimeImmutable;
use Poland\Domain\Money;

/** Everything read out of one statement file, including what could not be read. */
final class ParsedStatement implements \JsonSerializable
{
    /**
     * @param list<BankTransaction> $transactions
     * @param list<string> $problems lines that could not be read, described
     */
    public function __construct(
        public readonly string $format,
        public readonly array $transactions,
        public readonly array $problems = [],
        public readonly ?string $accountNumber = null,
        public readonly ?DateTimeImmutable $periodFrom = null,
        public readonly ?DateTimeImmutable $periodTo = null,
        public readonly ?Money $openingBalance = null,
        public readonly ?Money $closingBalance = null,
    ) {
    }

    public function count(): int
    {
        return count($this->transactions);
    }

    /**
     * Whether the transactions add up from the opening to the closing balance.
     *
     * The single most valuable check on a statement import: if the arithmetic
     * does not close, rows were dropped, and importing a partial statement
     * silently understates costs. Null when the file gave no balances.
     */
    public function balancesReconcile(): ?bool
    {
        if ($this->openingBalance === null || $this->closingBalance === null) {
            return null;
        }

        $running = $this->openingBalance;
        foreach ($this->transactions as $transaction) {
            $running = $running->plus($transaction->amount);
        }

        return $running->equals($this->closingBalance);
    }

    public function isTrustworthy(): bool
    {
        return $this->problems === [] && $this->balancesReconcile() !== false;
    }

    /**
     * Whether this statement covers a whole settlement month.
     *
     * "3 transactions imported" does not mean "all 3 transactions for August".
     * A statement covering 1-15 August is not the month, and treating it as one
     * understates costs exactly as silently as importing nothing.
     *
     * Returns null when the file states no period at all — unknown, which is
     * neither complete nor incomplete and must not be reported as either.
     *
     * @return array{status: string, covered: string|null, complete: bool|null}
     */
    public function coverageOf(\Poland\Domain\Period $period): array
    {
        $from = $this->periodFrom;
        $to = $this->periodTo;

        if ($from === null || $to === null) {
            return [
                'status' => 'UNKNOWN',
                'covered' => null,
                'complete' => null,
            ];
        }

        $monthStart = $period->firstDay();
        $monthEnd = $period->lastDay()->setTime(23, 59, 59);

        $covered = sprintf('%s - %s', $from->format('d.m.Y'), $to->format('d.m.Y'));

        if ($to < $monthStart || $from > $monthEnd) {
            return ['status' => 'OUTSIDE_PERIOD', 'covered' => $covered, 'complete' => false];
        }

        // Complete means the statement reaches both ends of the month. A
        // statement that starts on the 3rd may simply have had no activity on
        // the 1st and 2nd — which is why MT940 and camt opening balances
        // matter, and why a format without them can only ever be UNKNOWN.
        $startsEarlyEnough = $from <= $monthStart
            || ($this->openingBalance !== null && $from->format('Y-m') === $period->toString());
        $endsLateEnough = $to >= $period->lastDay()->setTime(0, 0)
            || ($this->closingBalance !== null && $to->format('Y-m') === $period->toString());

        if ($startsEarlyEnough && $endsLateEnough) {
            return ['status' => 'COMPLETE', 'covered' => $covered, 'complete' => true];
        }

        return ['status' => 'PARTIAL', 'covered' => $covered, 'complete' => false];
    }

    public function jsonSerialize(): array
    {
        return [
            'format' => $this->format,
            'account_number' => $this->accountNumber,
            'period_from' => $this->periodFrom?->format('Y-m-d'),
            'period_to' => $this->periodTo?->format('Y-m-d'),
            'opening_balance' => $this->openingBalance,
            'closing_balance' => $this->closingBalance,
            'count' => $this->count(),
            'balances_reconcile' => $this->balancesReconcile(),
            'is_trustworthy' => $this->isTrustworthy(),
            'has_balances' => $this->openingBalance !== null && $this->closingBalance !== null,
            'problems' => $this->problems,
            'transactions' => $this->transactions,
        ];
    }
}
