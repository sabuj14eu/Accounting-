<?php

declare(strict_types=1);

namespace Shop\Truth;

use InvalidArgumentException;

/**
 * An exact PLN amount held as an integer number of grosze.
 *
 * This is deliberately a SECOND implementation rather than an import of the
 * accounting engine's Money. The isolation contract says this application must
 * keep working when the accounting application is offline or absent, and a
 * shared class is a shared dependency. The duplication is the price of the
 * guarantee, and it is worth paying: a hundred lines against a coupling that no
 * later test could prove was gone.
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

    /** Parse "5 000,00", "5000.00", "5,000.00", 5000, 5000.5 — strictly. */
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

        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', 'zł', 'PLN', 'pln'], '', $raw);

        $negative = false;
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = substr($s, 1);
        } elseif (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // The LAST separator is the decimal one; the other is thousands.
            $s = strrpos($s, ',') > strrpos($s, '.')
                ? str_replace('.', '', $s)
                : str_replace(',', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($hasComma) {
            $parts = explode(',', $s);
            if (count($parts) > 2) {
                throw new InvalidArgumentException("Ambiguous amount: {$raw}");
            }
            // "1,234" is unreadable: it is either 1.23 zł or 1234 zł. Refuse.
            if (strlen($parts[1]) === 3) {
                throw new InvalidArgumentException(
                    "Ambiguous amount '{$raw}': three digits after a comma could be thousands or grosze."
                );
            }
            $s = $parts[0].'.'.$parts[1];
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            throw new InvalidArgumentException("Unreadable amount: {$raw}");
        }

        [$whole, $frac] = array_pad(explode('.', $s), 2, '0');
        $grosze = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

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

    /** Round half away from zero — the direction Polish invoicing uses. */
    public function times(float $factor): self
    {
        $exact = $this->grosze * $factor;

        return new self((int) ($exact >= 0 ? floor($exact + 0.5) : ceil($exact - 0.5)));
    }

    public function percent(float $percent): self
    {
        return $this->times($percent / 100);
    }

    public function negated(): self
    {
        return new self(-$this->grosze);
    }

    public function absolute(): self
    {
        return new self(abs($this->grosze));
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

    public static function sum(self ...$amounts): self
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount->grosze;
        }

        return new self($total);
    }

    /** Share of another amount, as a percentage. Null when the base is zero. */
    public function shareOf(self $base): ?float
    {
        if ($base->grosze === 0) {
            return null;
        }

        return round($this->grosze / $base->grosze * 100, 2);
    }

    public function format(): string
    {
        $sign = $this->grosze < 0 ? '-' : '';
        $abs = abs($this->grosze);

        return sprintf('%s%s,%02d zł', $sign, number_format(intdiv($abs, 100), 0, ',', ' '), $abs % 100);
    }

    public function jsonSerialize(): array
    {
        return ['grosze' => $this->grosze, 'formatted' => $this->format()];
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
