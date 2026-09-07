<?php

declare(strict_types=1);

namespace Shop\Truth;

/**
 * One amount, inseparable from the evidence behind it.
 *
 * There is no constructor that takes an amount without an EvidenceType, and no
 * setter for the label. A figure cannot be laundered by passing it around.
 */
final class Figure implements \JsonSerializable
{
    public function __construct(
        public readonly Money $amount,
        public readonly EvidenceType $evidence,
        public readonly string $description,
        public readonly ?string $sourceReference = null,
        public readonly ?string $occurredOn = null,
    ) {
        if (trim($description) === '') {
            throw new \InvalidArgumentException('A figure must say what it is.');
        }
    }

    public function provenance(): Provenance
    {
        return $this->evidence->provenance();
    }

    public function certainty(): Certainty
    {
        return $this->evidence->certainty();
    }

    public function isCorroborated(): bool
    {
        return $this->evidence->isIndependentlyCorroborated();
    }

    public function withAmount(Money $amount): self
    {
        return new self($amount, $this->evidence, $this->description, $this->sourceReference, $this->occurredOn);
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount->grosze,
            'formatted' => $this->amount->format(),
            'evidence' => $this->evidence->value,
            'certainty' => $this->certainty()->value,
            'provenance' => $this->provenance()->value,
            'corroborated' => $this->isCorroborated(),
            'description' => $this->description,
            'source_reference' => $this->sourceReference,
            'occurred_on' => $this->occurredOn,
        ];
    }
}
