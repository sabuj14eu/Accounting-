<?php

declare(strict_types=1);

namespace Shop\Money;

use Shop\Truth\Money;

/**
 * One version of a match between a bank line and a document. Immutable.
 *
 * Corrections do not edit this; they append another one. The banking-app rule
 * applies here exactly as it does to a ledger: you do not rub out an entry, you
 * post a correcting entry beside it and both stay visible.
 */
final class MatchRevision implements \JsonSerializable
{
    public function __construct(
        public readonly int $revision,
        public readonly ?string $documentReference,
        public readonly Money $amount,
        public readonly float $confidence,
        public readonly string $reason,
        public readonly string $changedBy,
        public readonly string $changedAt,
        public readonly ?string $correctionReason = null,
    ) {
        if ($revision < 1) {
            throw new \InvalidArgumentException('Revisions are numbered from 1.');
        }

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Confidence is a fraction between 0 and 1.');
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A match must say why it was made.');
        }

        if (trim($changedBy) === '') {
            throw new \InvalidArgumentException('A match revision must name who made it.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/', $changedAt)) {
            throw new \InvalidArgumentException("Timestamp '{$changedAt}' is not an ISO-8601 date-time.");
        }
    }

    public function isUnmatched(): bool
    {
        return $this->documentReference === null;
    }

    public function jsonSerialize(): array
    {
        return [
            'revision' => $this->revision,
            'document_reference' => $this->documentReference,
            'amount' => $this->amount->grosze,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'changed_by' => $this->changedBy,
            'changed_at' => $this->changedAt,
            'correction_reason' => $this->correctionReason,
        ];
    }
}
