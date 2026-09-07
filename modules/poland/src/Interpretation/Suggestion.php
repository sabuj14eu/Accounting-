<?php

declare(strict_types=1);

namespace Poland\Interpretation;

/**
 * Something an interpreter (AI, OCR, a heuristic) believes about a document.
 *
 * A suggestion is EVIDENCE, never a decision. Nothing in this package may turn
 * one into a ledger entry, a tax figure or a payment obligation on its own —
 * see {@see InterpretationBoundary}. It carries its confidence and its reason
 * so a human can judge it, and its source so the judgement can be re-checked.
 */
final class Suggestion implements \JsonSerializable
{
    public function __construct(
        /** What is being suggested about, e.g. "document_type", "counterparty". */
        public readonly string $field,
        public readonly mixed $value,
        /** 0.0 - 1.0. Never treated as a probability, only as an ordering. */
        public readonly float $confidence,
        /** Why the interpreter thinks so, in words a person can check. */
        public readonly string $reason,
        /** Which interpreter produced it, and from what. */
        public readonly string $source,
        /** The exact text or field the value was read from, when there is one. */
        public readonly ?string $evidence = null,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Confidence must be between 0 and 1.');
        }
    }

    public function isConfident(float $threshold = 0.85): bool
    {
        return $this->confidence >= $threshold;
    }

    public function jsonSerialize(): array
    {
        return [
            'field' => $this->field,
            'value' => $this->value instanceof \JsonSerializable ? $this->value : $this->value,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'source' => $this->source,
            'evidence' => $this->evidence,
        ];
    }
}
