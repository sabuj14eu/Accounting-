<?php

declare(strict_types=1);

namespace Poland\Inventory;

/**
 * What can honestly be said about one tracked product's stock.
 *
 *     opening count + purchases − implied consumption = physical count
 *
 * Sales are recorded MONTHLY, so nothing here decrements stock per sale.
 * Implied consumption is DERIVED from a count, and only when both an opening
 * count and a physical count exist. Without an opening count the position is
 * NO_OPENING_COUNT, without a physical count it is NOT_COUNTED — never zero
 * and never "fine".
 */
final class StockPosition implements \JsonSerializable
{
    public const NO_OPENING_COUNT = 'NO_OPENING_COUNT';

    public const NOT_COUNTED = 'NOT_COUNTED';

    public const COUNTED = 'COUNTED';

    public function __construct(
        public readonly string $productName,
        public readonly StockUnit $unit,
        public readonly ?StockQuantity $openingCount,
        public readonly StockQuantity $purchases,
        public readonly ?StockQuantity $physicalCount = null,
        public readonly ?\DateTimeImmutable $countedOn = null,
    ) {
        foreach ([$openingCount, $physicalCount] as $q) {
            if ($q !== null && $q->unit !== $unit) {
                throw new \InvalidArgumentException('Stock position mixes units.');
            }
        }
        if ($purchases->unit !== $unit) {
            throw new \InvalidArgumentException('Stock position mixes units.');
        }
    }

    public function status(): string
    {
        if ($this->openingCount === null) {
            return self::NO_OPENING_COUNT;
        }

        return $this->physicalCount === null ? self::NOT_COUNTED : self::COUNTED;
    }

    /** Opening plus purchases — what the book says should be there. Null without an opening count. */
    public function bookQuantity(): ?StockQuantity
    {
        if ($this->openingCount === null) {
            return null;
        }

        return $this->openingCount->plus($this->purchases);
    }

    /** Book minus counted. Positive = consumed or missing. Null unless both ends are known. */
    public function impliedConsumption(): ?StockQuantity
    {
        $book = $this->bookQuantity();
        if ($book === null || $this->physicalCount === null) {
            return null;
        }

        return $book->minus($this->physicalCount);
    }

    public function describe(): string
    {
        return match ($this->status()) {
            self::NO_OPENING_COUNT => sprintf(
                '%s: zakupy %s, BRAK STANU POCZĄTKOWEGO — stan magazynu nieznany, nie zero.',
                $this->productName,
                $this->purchases->format(),
            ),
            self::NOT_COUNTED => sprintf(
                '%s: stan początkowy %s + zakupy %s = %s wg ksiąg, NIE POLICZONO.',
                $this->productName,
                $this->openingCount->format(),
                $this->purchases->format(),
                $this->bookQuantity()->format(),
            ),
            default => sprintf(
                '%s: wg ksiąg %s, policzono %s, zużycie/różnica %s.',
                $this->productName,
                $this->bookQuantity()->format(),
                $this->physicalCount->format(),
                $this->impliedConsumption()->format(),
            ),
        };
    }

    public function jsonSerialize(): array
    {
        return [
            'product' => $this->productName,
            'unit' => $this->unit->value,
            'opening_count' => $this->openingCount,
            'purchases' => $this->purchases,
            'book_quantity' => $this->bookQuantity(),
            'physical_count' => $this->physicalCount,
            'counted_on' => $this->countedOn?->format('Y-m-d'),
            'implied_consumption' => $this->impliedConsumption(),
            'status' => $this->status(),
            'description' => $this->describe(),
        ];
    }
}
