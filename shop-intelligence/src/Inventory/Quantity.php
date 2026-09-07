<?php

declare(strict_types=1);

namespace Shop\Inventory;

use InvalidArgumentException;

/**
 * A quantity of one ingredient, held as an integer count of thousandths of its
 * base unit (grams, millilitres, pieces).
 *
 * Floats are wrong here for the same reason they are wrong for money: 0.1 kg of
 * mince, added up over three hundred kebabs, does not come back as 30 kg. The
 * unit is part of the value, and arithmetic across units throws rather than
 * silently treating millilitres as grams.
 */
final class Quantity implements \JsonSerializable
{
    public const GRAM = 'g';
    public const MILLILITRE = 'ml';
    public const PIECE = 'szt';

    private function __construct(
        public readonly int $thousandths,
        public readonly string $unit,
    ) {
    }

    public static function of(float|int|string $amount, string $unit): self
    {
        if (! in_array($unit, [self::GRAM, self::MILLILITRE, self::PIECE], true)) {
            throw new InvalidArgumentException("Unknown unit '{$unit}'.");
        }

        if (is_string($amount)) {
            $amount = str_replace([',', ' '], ['.', ''], trim($amount));
            if (! is_numeric($amount)) {
                throw new InvalidArgumentException("Unreadable quantity '{$amount}'.");
            }
            $amount = (float) $amount;
        }

        if (is_float($amount) && ! is_finite($amount)) {
            throw new InvalidArgumentException('Quantity is not a finite number.');
        }

        return new self((int) round($amount * 1000), $unit);
    }

    public static function zero(string $unit): self
    {
        return self::of(0, $unit);
    }

    private function assertSameUnit(self $other): void
    {
        if ($this->unit !== $other->unit) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->unit} with {$other->unit}: they are not the same thing."
            );
        }
    }

    public function plus(self $other): self
    {
        $this->assertSameUnit($other);

        return new self($this->thousandths + $other->thousandths, $this->unit);
    }

    public function minus(self $other): self
    {
        $this->assertSameUnit($other);

        return new self($this->thousandths - $other->thousandths, $this->unit);
    }

    public function times(float|int $factor): self
    {
        $exact = $this->thousandths * $factor;

        return new self((int) ($exact >= 0 ? floor($exact + 0.5) : ceil($exact - 0.5)), $this->unit);
    }

    public function absolute(): self
    {
        return new self(abs($this->thousandths), $this->unit);
    }

    public function isZero(): bool
    {
        return $this->thousandths === 0;
    }

    public function isNegative(): bool
    {
        return $this->thousandths < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameUnit($other);

        return $this->thousandths > $other->thousandths;
    }

    public function equals(self $other): bool
    {
        return $this->unit === $other->unit && $this->thousandths === $other->thousandths;
    }

    public function toFloat(): float
    {
        return $this->thousandths / 1000;
    }

    /** Share of another quantity, as a percentage. Null when the base is zero. */
    public function shareOf(self $base): ?float
    {
        $this->assertSameUnit($base);

        if ($base->thousandths === 0) {
            return null;
        }

        return round($this->thousandths / $base->thousandths * 100, 2);
    }

    public function format(): string
    {
        $value = rtrim(rtrim(number_format($this->toFloat(), 3, ',', ' '), '0'), ',');

        return ($value === '' ? '0' : $value).' '.$this->unit;
    }

    public function jsonSerialize(): array
    {
        return ['thousandths' => $this->thousandths, 'unit' => $this->unit, 'formatted' => $this->format()];
    }
}
