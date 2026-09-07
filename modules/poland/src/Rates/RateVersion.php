<?php

declare(strict_types=1);

namespace Poland\Rates;

use Poland\Domain\Money;
use Poland\Domain\Period;
use RuntimeException;

/**
 * One effective-dated slice of a rate table, plus the provenance of its
 * numbers. Every figure the engine reports can name the version it came from.
 */
final class RateVersion
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $table,
        public readonly Period $effectiveFrom,
        public readonly ?Period $effectiveTo,
        public readonly array $data,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(string $table, array $row): self
    {
        return new self(
            $table,
            Period::parse((string) $row['effective_from']),
            isset($row['effective_to']) && $row['effective_to'] !== null
                ? Period::parse((string) $row['effective_to'])
                : null,
            $row,
        );
    }

    public function covers(Period $period): bool
    {
        if ($period->isBefore($this->effectiveFrom)) {
            return false;
        }

        return $this->effectiveTo === null || ! $period->isAfter($this->effectiveTo);
    }

    public function get(string $key): mixed
    {
        $value = $this->data;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new RuntimeException(sprintf(
                    'Rate table "%s" version %s has no key "%s".',
                    $this->table,
                    $this->effectiveFrom->toString(),
                    $key,
                ));
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function money(string $key): Money
    {
        return Money::parse((string) $this->get($key));
    }

    public function rate(string $key): float
    {
        return (float) $this->get($key);
    }

    public function source(): string
    {
        return (string) ($this->data['source'] ?? 'unspecified');
    }

    public function verifiedOn(): ?string
    {
        return isset($this->data['verified_on']) ? (string) $this->data['verified_on'] : null;
    }

    /** Human-readable provenance stamp attached to every reported figure. */
    public function stamp(): string
    {
        $window = $this->effectiveFrom->toString().' — '.($this->effectiveTo?->toString() ?? 'obowiązuje');

        return sprintf('%s [%s] źródło: %s', $this->table, $window, $this->source());
    }
}
