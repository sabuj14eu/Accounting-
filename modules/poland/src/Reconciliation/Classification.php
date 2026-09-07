<?php

declare(strict_types=1);

namespace Poland\Reconciliation;

use Poland\Banking\BankTransaction;

/**
 * A decision about one bank transaction, with everything needed to check it.
 *
 * Confidence alone is not enough to act on: the REASON has to be readable, so a
 * person can tell "the reference FV/2026/08/417 appears in the description and
 * the amount matches to the grosz" from "the amount is about right".
 */
final class Classification implements \JsonSerializable
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly BankTransaction $transaction,
        public readonly TransactionCategory $category,
        public readonly float $confidence,
        public readonly array $reasons,
        /** Identifier of the document this was matched to, if any. */
        public readonly ?string $matchedDocument = null,
        public readonly ?string $matchedDocumentType = null,
        public readonly MatchQuality $quality = MatchQuality::Unmatched,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Confidence must be between 0 and 1.');
        }
    }

    public static function needsReview(BankTransaction $transaction, string $reason): self
    {
        return new self(
            $transaction,
            TransactionCategory::NeedsReview,
            0.0,
            [$reason],
            null,
            null,
            MatchQuality::Unmatched,
        );
    }

    /**
     * Whether this may be booked without a human looking.
     *
     * Requires a bookable category, an exact match and high confidence — all
     * three. An uncertain transaction is never booked automatically, which is
     * the whole point of the review queue.
     */
    public function isAutoBookable(float $threshold = 0.9): bool
    {
        return $this->category->isBookable()
            && $this->quality === MatchQuality::Matched
            && $this->confidence >= $threshold;
    }

    public function reason(): string
    {
        return implode('; ', $this->reasons);
    }

    public function jsonSerialize(): array
    {
        return [
            'classification' => $this->category->value,
            'classification_label' => $this->category->label(),
            'confidence' => $this->confidence,
            'reason' => $this->reason(),
            'reasons' => $this->reasons,
            'match_quality' => $this->quality->value,
            'matched_document' => $this->matchedDocument,
            'matched_document_type' => $this->matchedDocumentType,
            'auto_bookable' => $this->isAutoBookable(),
            'source_transaction' => $this->transaction,
        ];
    }
}
