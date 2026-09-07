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
            'problems' => $this->problems,
            'transactions' => $this->transactions,
        ];
    }
}
