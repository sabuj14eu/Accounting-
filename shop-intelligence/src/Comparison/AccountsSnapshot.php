<?php

declare(strict_types=1);

namespace Shop\Comparison;

use Shop\Truth\Money;
use Shop\Truth\Provenance;

/**
 * Figures copied FROM the official accounting system, one way — §27.
 *
 * This is the only place the word "official" appears attached to a number in
 * this application, and it is deliberately a dumb container: it holds what
 * somebody imported or typed in from the accounting side, together with when
 * and by whom, and it computes nothing. There is no client, no connection
 * string and no credential anywhere near it. That is what makes §28 provable
 * rather than merely asserted.
 */
final class AccountsSnapshot implements \JsonSerializable
{
    public function __construct(
        public readonly string $period,
        public readonly Money $revenue,
        public readonly Money $costs,
        public readonly string $importedBy,
        public readonly string $importedAt,
        public readonly string $sourceDescription,
    ) {
        if (trim($importedBy) === '' || trim($sourceDescription) === '') {
            throw new \InvalidArgumentException(
                'A snapshot of official figures must say who brought it across and from what. '
                .'An unattributed "official" number is the worst kind in this application.'
            );
        }
    }

    public function provenance(): Provenance
    {
        return Provenance::OFFICIAL_ACCOUNTING_FACT;
    }

    public function result(): Money
    {
        return $this->revenue->minus($this->costs);
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period,
            'revenue' => $this->revenue->grosze,
            'costs' => $this->costs->grosze,
            'result' => $this->result()->grosze,
            'provenance' => $this->provenance()->value,
            'imported_by' => $this->importedBy,
            'imported_at' => $this->importedAt,
            'source_description' => $this->sourceDescription,
        ];
    }
}
