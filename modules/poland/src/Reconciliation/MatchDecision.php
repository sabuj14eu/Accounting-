<?php

declare(strict_types=1);

namespace Poland\Reconciliation;

use DateTimeImmutable;

/** A human accepting or rejecting a suggested match. Always audited. */
final class MatchDecision implements \JsonSerializable
{
    public function __construct(
        public readonly string $transactionFingerprint,
        public readonly ?string $documentReference,
        public readonly bool $accepted,
        public readonly string $decidedBy,
        public readonly DateTimeImmutable $decidedAt,
        public readonly ?string $note = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'transaction' => $this->transactionFingerprint,
            'document' => $this->documentReference,
            'accepted' => $this->accepted,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt->format(DATE_ATOM),
            'note' => $this->note,
        ];
    }
}
