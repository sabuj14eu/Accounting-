<?php

declare(strict_types=1);

namespace Poland\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A settlement month (okres rozliczeniowy). Everything in this package is
 * settled monthly or derived from a run of months, so the month — not a free
 * date range — is the unit.
 */
final class Period implements \JsonSerializable
{
    private function __construct(
        public readonly int $year,
        public readonly int $month,
    ) {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Month must be 1-12, got {$month}.");
        }
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("Year out of supported range, got {$year}.");
        }
    }

    public static function of(int $year, int $month): self
    {
        return new self($year, $month);
    }

    /** Accepts "2026-08" or "2026-8". */
    public static function parse(string $value): self
    {
        if (preg_match('/^(\d{4})-(\d{1,2})$/', trim($value), $m) !== 1) {
            throw new InvalidArgumentException("Period must look like YYYY-MM, got \"{$value}\".");
        }

        return new self((int) $m[1], (int) $m[2]);
    }

    public function firstDay(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $this->year, $this->month));
    }

    public function lastDay(): DateTimeImmutable
    {
        return $this->firstDay()->modify('last day of this month');
    }

    public function next(): self
    {
        return $this->month === 12
            ? new self($this->year + 1, 1)
            : new self($this->year, $this->month + 1);
    }

    public function previous(): self
    {
        return $this->month === 1
            ? new self($this->year - 1, 12)
            : new self($this->year, $this->month - 1);
    }

    /** Every month of this period's calendar year up to and including this one. */
    public function yearToDate(): array
    {
        $months = [];
        for ($m = 1; $m <= $this->month; $m++) {
            $months[] = new self($this->year, $m);
        }

        return $months;
    }

    /** The quarter this month falls in, 1-4. */
    public function quarter(): int
    {
        return intdiv($this->month - 1, 3) + 1;
    }

    public function isQuarterEnd(): bool
    {
        return $this->month % 3 === 0;
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }

    public function isBefore(self $other): bool
    {
        return $this->sortKey() < $other->sortKey();
    }

    public function isAfter(self $other): bool
    {
        return $this->sortKey() > $other->sortKey();
    }

    public function sortKey(): int
    {
        return $this->year * 12 + $this->month;
    }

    /** Whole months from $other to $this (negative if $this is earlier). */
    public function monthsSince(self $other): int
    {
        return $this->sortKey() - $other->sortKey();
    }

    public function toString(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function label(): string
    {
        static $names = [
            1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
            'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień',
        ];

        return $names[$this->month].' '.$this->year;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
