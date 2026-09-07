<?php

declare(strict_types=1);

namespace Poland\Ksef;

use DateTimeImmutable;

/**
 * What one synchronisation run did.
 *
 * `failed` and `skipped` are separate from `imported` on purpose: a run that
 * imported 3 and failed on 7 is not a successful run, and a summary that only
 * counted successes would report it as one.
 */
final class KsefSyncResult implements \JsonSerializable
{
    /**
     * @param list<string> $importedNumbers
     * @param list<string> $duplicateNumbers
     * @param array<string,string> $failures ksefNumber => reason
     * @param list<string> $affectedPeriods periods whose reports may now be stale
     */
    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly array $importedNumbers = [],
        public readonly array $duplicateNumbers = [],
        public readonly array $failures = [],
        public readonly array $affectedPeriods = [],
        public readonly bool $completed = true,
        public readonly ?string $stoppedBecause = null,
        public readonly ?DateTimeImmutable $resumeFrom = null,
    ) {
    }

    public function importedCount(): int
    {
        return count($this->importedNumbers);
    }

    public function isClean(): bool
    {
        return $this->completed && $this->failures === [];
    }

    public function summary(): string
    {
        if (! $this->completed) {
            return sprintf(
                'Synchronizacja PRZERWANA: %s. Pobrano %d, duplikaty %d, błędy %d. '
                .'Wznowienie od %s — nic nie zostało pominięte.',
                $this->stoppedBecause ?? 'nieznany powód',
                $this->importedCount(),
                count($this->duplicateNumbers),
                count($this->failures),
                $this->resumeFrom?->format('Y-m-d') ?? $this->from->format('Y-m-d'),
            );
        }

        return sprintf(
            'Pobrano %d nowych faktur, %d duplikatów pominięto, %d błędów.',
            $this->importedCount(),
            count($this->duplicateNumbers),
            count($this->failures),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'imported' => $this->importedNumbers,
            'imported_count' => $this->importedCount(),
            'duplicates' => $this->duplicateNumbers,
            'failures' => $this->failures,
            'affected_periods' => $this->affectedPeriods,
            'completed' => $this->completed,
            'stopped_because' => $this->stoppedBecause,
            'resume_from' => $this->resumeFrom?->format('Y-m-d'),
            'is_clean' => $this->isClean(),
            'summary' => $this->summary(),
        ];
    }
}
