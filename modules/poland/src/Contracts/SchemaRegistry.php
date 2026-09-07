<?php

declare(strict_types=1);

namespace Poland\Contracts;

use Poland\Domain\Period;
use RuntimeException;

/**
 * Which schema version applies to a given structure and period.
 *
 * Selection is by period, so a correction to an old month is prepared under the
 * schema that was current for that month. Asking for a period no registered
 * version covers is an error, not a fallback to the newest — the same refusal
 * the rate tables make, for the same reason.
 */
final class SchemaRegistry
{
    /** @var array<string,list<SchemaVersion>> */
    private array $versions = [];

    /** @param list<SchemaVersion> $versions */
    public function __construct(array $versions = [])
    {
        foreach ($versions as $version) {
            $this->register($version);
        }
    }

    public function register(SchemaVersion $version): self
    {
        $this->versions[$version->structure][] = $version;

        return $this;
    }

    public function for(string $structure, Period $period): SchemaVersion
    {
        foreach ($this->versions[$structure] ?? [] as $version) {
            if ($version->covers($period)) {
                return $version;
            }
        }

        throw new RuntimeException(sprintf(
            'No "%s" schema version is registered for %s. Register the version that was current '
            .'for that period — preparing an old period under a newer schema produces a document '
            .'the authority will reject, and preparing it under a guessed one is worse.',
            $structure,
            $period->toString(),
        ));
    }

    public function has(string $structure, Period $period): bool
    {
        foreach ($this->versions[$structure] ?? [] as $version) {
            if ($version->covers($period)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<SchemaVersion> */
    public function all(?string $structure = null): array
    {
        if ($structure !== null) {
            return $this->versions[$structure] ?? [];
        }

        return array_merge(...array_values($this->versions ?: [[]]));
    }
}
