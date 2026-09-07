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
    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $meanings key => what the value means and how it is derived
     */
    public function __construct(
        public readonly string $table,
        public readonly string $version,
        public readonly Period $effectiveFrom,
        public readonly ?Period $effectiveTo,
        public readonly RateProvenance $provenance,
        public readonly array $data,
        public readonly array $meanings = [],
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(string $table, array $row): self
    {
        foreach (['version', 'effective_from', 'provenance'] as $required) {
            if (! array_key_exists($required, $row)) {
                throw new RuntimeException(sprintf(
                    'Rate table "%s" has a version missing "%s". Every rate version must carry '
                    .'its own version identifier, its effective period and its provenance — a rate '
                    .'nobody can trace is a rate nobody can defend.',
                    $table,
                    $required,
                ));
            }
        }

        return new self(
            $table,
            (string) $row['version'],
            Period::parse((string) $row['effective_from']),
            isset($row['effective_to']) && $row['effective_to'] !== null
                ? Period::parse((string) $row['effective_to'])
                : null,
            RateProvenance::fromArray((array) $row['provenance']),
            $row,
            (array) ($row['meanings'] ?? []),
        );
    }

    /** What a value in this version means, and how it is derived. */
    public function meaning(string $key): ?string
    {
        return $this->meanings[$key] ?? null;
    }

    public function isFitForFiling(): bool
    {
        return $this->provenance->status->fitForFiling();
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
        return $this->provenance->sourceDocument;
    }

    public function verifiedOn(): string
    {
        return $this->provenance->checkedOn;
    }

    /**
     * Human-readable provenance stamp attached to every reported figure.
     *
     * The verification status is part of the stamp rather than a separate
     * field, so a figure can never be quoted without it.
     */
    public function stamp(): string
    {
        $window = $this->effectiveFrom->toString().' — '.($this->effectiveTo?->toString() ?? 'obowiązuje');

        return sprintf(
            '%s v%s [%s] · %s · źródło: %s%s',
            $this->table,
            $this->version,
            $window,
            $this->provenance->status->label(),
            $this->provenance->sourceDocument,
            $this->provenance->sourceUrl !== '' ? ' <'.$this->provenance->sourceUrl.'>' : '',
        );
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return [
            'table' => $this->table,
            'version' => $this->version,
            'effective_from' => $this->effectiveFrom->toString(),
            'effective_to' => $this->effectiveTo?->toString(),
            'provenance' => $this->provenance,
            'stamp' => $this->stamp(),
        ];
    }
}
