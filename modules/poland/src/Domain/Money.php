<?php

declare(strict_types=1);

namespace Poland\Domain;

use InvalidArgumentException;

/**
 * An exact PLN amount held as an integer number of grosze.
 *
 * Polish tax arithmetic is legally defined on exact decimal amounts, so no
 * money value in this package is ever a float. Everything that leaves this
 * class as a float is for display only and is named accordingly.
 */
final class Money implements \JsonSerializable
{
    private function __construct(public readonly int $grosze)
    {
    }

    public static function grosze(int $grosze): self
    {
        return new self($grosze);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a human amount: "48 500,00", "48500.00", "48,500.00", 48500, 48500.5.
     *
     * Ambiguous thousands/decimal separators are the single most common way a
     * cash-register total gets entered wrong by a factor of 100, so the parser
     * is strict rather than forgiving: it rejects anything it cannot read one
     * way only.
     */
    public static function parse(string|int|float $value): self
    {
        if (is_int($value)) {
            return new self($value * 100);
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Amount is not a finite number.');
            }

            return new self((int) round($value * 100));
        }

        $raw = trim($value);
        if ($raw === '') {
            throw new InvalidArgumentException('Amount is empty.');
        }

        // Strip currency markers and every kind of thousands space.
        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', 'zł', 'PLN', 'pln'], '', $raw);

        $negative = str_starts_with($s, '-');
        $s = ltrim($s, '+-');

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // The rightmost separator is the decimal one; the other is thousands.
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            // "1,234" is ambiguous. Polish decimal comma always has 1-2 digits
            // after it; a 3-digit group is a thousands separator.
            $tail = substr($s, strrpos($s, ',') + 1);
            $s = strlen($tail) === 3 && substr_count($s, ',') >= 1 && preg_match('/^\d{1,3}(,\d{3})+$/', $s) === 1
                ? str_replace(',', '', $s)
                : str_replace(',', '.', $s);
        }

        if (preg_match('/^\d+(\.\d+)?$/', $s) !== 1) {
            throw new InvalidArgumentException(sprintf('Cannot read "%s" as a PLN amount.', $raw));
        }

        [$whole, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $frac = substr($frac.'00', 0, 2);

        $grosze = ((int) $whole) * 100 + (int) $frac;

        return new self($negative ? -$grosze : $grosze);
    }

    public function plus(self $other): self
    {
        return new self($this->grosze + $other->grosze);
    }

    public function minus(self $other): self
    {
        return new self($this->grosze - $other->grosze);
    }

    /** Multiply by a rate, rounding half-up (away from zero) to whole grosze. */
    public function times(float $rate): self
    {
        return new self($this->roundHalfUp($this->grosze * $rate));
    }

    /** Divide by a divisor, rounding half-up to whole grosze. */
    public function dividedBy(float $divisor): self
    {
        if ($divisor == 0.0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self($this->roundHalfUp($this->grosze / $divisor));
    }

    /**
     * Round to full złoty, as Ordynacja podatkowa art. 63 § 1 requires for tax
     * amounts and tax bases (below 50 gr down, 50 gr and above up).
     */
    public function roundedToZloty(): self
    {
        $sign = $this->grosze < 0 ? -1 : 1;
        $abs = abs($this->grosze);
        $zloty = intdiv($abs, 100);
        if ($abs % 100 >= 50) {
            $zloty++;
        }

        return new self($sign * $zloty * 100);
    }

    public function isZero(): bool
    {
        return $this->grosze === 0;
    }

    public function isNegative(): bool
    {
        return $this->grosze < 0;
    }

    public function isPositive(): bool
    {
        return $this->grosze > 0;
    }

    public function equals(self $other): bool
    {
        return $this->grosze === $other->grosze;
    }

    public function greaterThan(self $other): bool
    {
        return $this->grosze > $other->grosze;
    }

    public function lessThan(self $other): bool
    {
        return $this->grosze < $other->grosze;
    }

    /** Never let a computed liability go negative when the law floors it at zero. */
    public function clampAtZero(): self
    {
        return $this->grosze < 0 ? self::zero() : $this;
    }

    public static function max(self $a, self $b): self
    {
        return $a->grosze >= $b->grosze ? $a : $b;
    }

    public static function min(self $a, self $b): self
    {
        return $a->grosze <= $b->grosze ? $a : $b;
    }

    /** @param iterable<self> $items */
    public static function sum(iterable $items): self
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $item->grosze;
        }

        return new self($total);
    }

    /** Display only — never feed this back into a calculation. */
    public function toFloat(): float
    {
        return $this->grosze / 100;
    }

    public function format(string $suffix = ' zł'): string
    {
        $sign = $this->grosze < 0 ? '-' : '';
        $abs = abs($this->grosze);
        $whole = number_format(intdiv($abs, 100), 0, ',', "\u{00A0}");

        return $sign.$whole.','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT).$suffix;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): string
    {
        $sign = $this->grosze < 0 ? '-' : '';
        $abs = abs($this->grosze);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function roundHalfUp(float $value): int
    {
        return (int) ($value < 0 ? -floor(-$value + 0.5) : floor($value + 0.5));
    }
}
