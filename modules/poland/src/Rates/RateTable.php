<?php

declare(strict_types=1);

namespace Poland\Rates;

use Poland\Domain\Period;

/** An effective-dated set of versions for one body of rules. */
final class RateTable
{
    /** @var list<RateVersion> */
    private array $versions;

    /** @param array<string,mixed> $config */
    public function __construct(
        public readonly string $name,
        private readonly array $config,
    ) {
        $this->versions = array_map(
            fn (array $row): RateVersion => RateVersion::fromArray($name, $row),
            $config['versions'] ?? [],
        );

        usort(
            $this->versions,
            fn (RateVersion $a, RateVersion $b): int => $a->effectiveFrom->sortKey() <=> $b->effectiveFrom->sortKey(),
        );

        $this->assertNoOverlaps();
    }

    /** @throws MissingRateException when the period is not covered. */
    public function for(Period $period): RateVersion
    {
        foreach ($this->versions as $version) {
            if ($version->covers($period)) {
                return $version;
            }
        }

        throw MissingRateException::for($this->name, $period);
    }

    public function covers(Period $period): bool
    {
        foreach ($this->versions as $version) {
            if ($version->covers($period)) {
                return true;
            }
        }

        return false;
    }

    /** Values that sit outside any version because they do not change by date. */
    public function constant(string $key): mixed
    {
        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new \RuntimeException(sprintf(
                    'Rate table "%s" has no constant "%s".',
                    $this->name,
                    $key,
                ));
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function hasConstant(string $key): bool
    {
        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /** @return list<RateVersion> */
    public function versions(): array
    {
        return $this->versions;
    }

    /**
     * The last period this table can settle. Shown in the UI so a gap is
     * visible before somebody hits it, not after.
     */
    public function coveredUntil(): ?Period
    {
        $last = end($this->versions);
        if ($last === false) {
            return null;
        }

        return $last->effectiveTo;
    }

    /**
     * Two versions covering the same month is a data error that would make the
     * engine's answer depend on array order. Caught at construction.
     */
    private function assertNoOverlaps(): void
    {
        $count = count($this->versions);
        for ($i = 0; $i + 1 < $count; $i++) {
            $current = $this->versions[$i];
            $next = $this->versions[$i + 1];

            if ($current->effectiveTo === null) {
                throw new \RuntimeException(sprintf(
                    'Rate table "%s": version from %s is open-ended but is followed by a version from %s.',
                    $this->name,
                    $current->effectiveFrom->toString(),
                    $next->effectiveFrom->toString(),
                ));
            }

            if (! $current->effectiveTo->isBefore($next->effectiveFrom)) {
                throw new \RuntimeException(sprintf(
                    'Rate table "%s": versions %s and %s overlap.',
                    $this->name,
                    $current->effectiveFrom->toString(),
                    $next->effectiveFrom->toString(),
                ));
            }
        }
    }
}
