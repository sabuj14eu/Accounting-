<?php

declare(strict_types=1);

namespace Poland\Contracts;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * One exchange rate with everything needed to defend a conversion: which
 * currency, which table, which publication date, and the rate itself.
 */
final class ExchangeRateQuote implements \JsonSerializable
{
    public function __construct(
        public readonly string $currency,
        public readonly float $rate,
        /** NBP table identifier, e.g. "A/123/2026/NBP". */
        public readonly string $tableId,
        public readonly DateTimeImmutable $tableDate,
        public readonly DateTimeImmutable $appliesTo,
        public readonly string $source,
    ) {
    }

    /** Convert a foreign amount to PLN at this exact rate. */
    public function toPln(Money $foreign): Money
    {
        return $foreign->times($this->rate);
    }

    public function jsonSerialize(): array
    {
        return [
            'currency' => $this->currency,
            'rate' => $this->rate,
            'table_id' => $this->tableId,
            'table_date' => $this->tableDate->format('Y-m-d'),
            'applies_to' => $this->appliesTo->format('Y-m-d'),
            'source' => $this->source,
        ];
    }
}
