<?php

declare(strict_types=1);

namespace Poland\Inventory;

use InvalidArgumentException;

/**
 * A signed quantity of one product, held as an integer count of thousandths
 * of its stock unit.
 *
 * Floats are wrong here for the reason they are wrong for money: 0,1 kg added
 * three hundred times does not come back as 30 kg. The unit travels with the
 * value and arithmetic across units throws rather than treating litres as
 * kilograms.
 */
final class StockQuantity implements \JsonSerializable
{
    private function __construct(
        public readonly int $thousandths,
        public readonly StockUnit $unit,
    ) {
    }

    public static function of(float|int|string $amount, StockUnit $unit): self
    {
        if (is_string($amount)) {
            $normalised = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim($amount));
            if (! is_numeric($normalised)) {
                throw new InvalidArgumentException(sprintf('Unreadable quantity "%s".', $amount));
            }
            $amount = (float) $normalised;
        }

        if (is_float($amount) && ! is_finite($amount)) {
            throw new InvalidArgumentException('Quantity is not a finite number.');
        }

        return new self((int) round($amount * 1000), $unit);
    }

    public static function zero(StockUnit $unit): self
    {
        return new self(0, $unit);
    }

    private function assertSameUnit(self $other): void
    {
        if ($this->unit !== $other->unit) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s: they are not the same thing.',
                $this->unit->value,
                $other->unit->value,
            ));
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

    public function times(float $factor): self
    {
        return new self((int) round($this->thousandths * $factor), $this->unit);
    }

    public function negated(): self
    {
        return new self(-$this->thousandths, $this->unit);
    }

    public function isZero(): bool
    {
        return $this->thousandths === 0;
    }

    public function isNegative(): bool
    {
        return $this->thousandths < 0;
    }

    public function isPositive(): bool
    {
        return $this->thousandths > 0;
    }

    public function equals(self $other): bool
    {
        return $this->unit === $other->unit && $this->thousandths === $other->thousandths;
    }

    public function toFloat(): float
    {
        return $this->thousandths / 1000;
    }

    /** "240 szt", "12,5 kg" — thousandths shown only when they are not zero. */
    public function format(): string
    {
        $value = $this->toFloat();
        $text = $this->thousandths % 1000 === 0
            ? number_format($value, 0, ',', ' ')
            : rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');

        return $text.' '.$this->unit->label();
    }

    public function jsonSerialize(): array
    {
        return [
            'thousandths' => $this->thousandths,
            'unit' => $this->unit->value,
            'display' => $this->format(),
        ];
    }
}
