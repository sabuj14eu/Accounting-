<?php

declare(strict_types=1);

namespace Poland\Rates;

use InvalidArgumentException;

/**
 * Where a rate version's numbers came from, and how far they can be trusted.
 *
 * Every field is required — including the awkward ones. A provenance record
 * with an empty source URL is how "we'll check that later" becomes "nobody
 * remembers whether we checked".
 */
final class RateProvenance implements \JsonSerializable
{
    public function __construct(
        public readonly VerificationStatus $status,
        /** What the source actually is: statute article, announcement, communiqué. */
        public readonly string $sourceDocument,
        /** Where to read it. Empty only when the source genuinely has no URL. */
        public readonly string $sourceUrl,
        /**
         * Where this figure MUST be confirmed before it is fit for filing —
         * the issuing authority's own publication.
         *
         * Separate from sourceUrl on purpose. When a rate is taken from the
         * trade press, sourceUrl says honestly where it actually came from,
         * and this says where somebody still has to go. Collapsing the two
         * would let a secondary source wear an official URL.
         */
        public readonly string $officialSourceUrl,
        /** When the source was published, where the source states it. */
        public readonly ?string $publishedOn,
        /** When somebody last checked this version against that source. */
        public readonly string $checkedOn,
        /** Who checked it — a person or a process, never blank. */
        public readonly string $checkedBy,
        public readonly string $notes = '',
    ) {
        if (trim($sourceDocument) === '') {
            throw new InvalidArgumentException('A rate version must name its source document.');
        }
        if (trim($checkedOn) === '' || trim($checkedBy) === '') {
            throw new InvalidArgumentException(
                'A rate version must record when it was checked and by whom.',
            );
        }
        if ($status === VerificationStatus::Official && trim($sourceUrl) === '') {
            throw new InvalidArgumentException(
                'A rate version marked official must carry the URL of the official publication, '
                .'so the claim can be re-checked by somebody who was not there.',
            );
        }
        if ($status !== VerificationStatus::Official && trim($officialSourceUrl) === '') {
            throw new InvalidArgumentException(
                'A rate version that is not yet official must say WHERE it has to be confirmed. '
                .'An unverified rate with no route to verification never gets verified.',
            );
        }
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        foreach (['status', 'source_document', 'checked_on', 'checked_by'] as $required) {
            if (! isset($row[$required])) {
                throw new InvalidArgumentException(
                    sprintf('Rate provenance is missing "%s".', $required),
                );
            }
        }

        $status = VerificationStatus::tryFrom((string) $row['status']);
        if ($status === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown verification status "%s". Allowed: %s.',
                (string) $row['status'],
                implode(', ', array_map(static fn ($c): string => $c->value, VerificationStatus::cases())),
            ));
        }

        // Named arguments on purpose: this constructor has eight string-ish
        // parameters, and a positional call silently survives a reordering.
        return new self(
            status: $status,
            sourceDocument: (string) $row['source_document'],
            sourceUrl: (string) ($row['source_url'] ?? ''),
            officialSourceUrl: (string) ($row['official_source_url'] ?? ''),
            publishedOn: isset($row['published_on']) ? (string) $row['published_on'] : null,
            checkedOn: (string) $row['checked_on'],
            checkedBy: (string) $row['checked_by'],
            notes: (string) ($row['notes'] ?? ''),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'fit_for_filing' => $this->status->fitForFiling(),
            'source_document' => $this->sourceDocument,
            'source_url' => $this->sourceUrl,
            'official_source_url' => $this->officialSourceUrl,
            'published_on' => $this->publishedOn,
            'checked_on' => $this->checkedOn,
            'checked_by' => $this->checkedBy,
            'notes' => $this->notes,
        ];
    }
}
