<?php

declare(strict_types=1);

namespace Poland\Contracts;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * Official exchange rates, as a port.
 *
 * NBP is the source Polish tax law points at, but it is one implementation of
 * this interface and not a dependency of the accounting core.
 *
 * The contract carries the table and date because Polish rules do not say
 * "convert at the exchange rate" — they say which table, from which day. A
 * conversion that does not record those two facts cannot be defended, and must
 * never be recomputed later from a newer rate.
 */
interface ExchangeRateProvider
{
    /**
     * The rate that applies to a transaction on a given date.
     *
     * Implementations must return the rate the LAW points at (for Polish tax
     * purposes, generally the average rate of the last working day before the
     * transaction), not the rate on the transaction date itself.
     */
    public function rateFor(string $currency, DateTimeImmutable $transactionDate): ExchangeRateQuote;

    public function isAvailable(): bool;
}
