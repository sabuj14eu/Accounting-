<?php

declare(strict_types=1);

namespace Shop\Cash;

use Shop\Truth\EvidenceType;
use Shop\Truth\Figure;
use Shop\Truth\Money;

/**
 * One movement of physical cash. Signed: in is positive, out is negative.
 */
final class CashMovement implements \JsonSerializable
{
    public function __construct(
        public readonly string $date,
        public readonly string $description,
        public readonly Money $amount,
        public readonly EvidenceType $evidence,
        public readonly ?string $reference = null,
    ) {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("Movement date '{$date}' is not YYYY-MM-DD.");
        }

        if ($amount->isZero()) {
            throw new \InvalidArgumentException('A zero cash movement is not a movement.');
        }
    }

    public function figure(): Figure
    {
        return new Figure($this->amount, $this->evidence, $this->description, $this->reference, $this->date);
    }

    public function isIn(): bool
    {
        return $this->amount->isPositive();
    }

    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date,
            'description' => $this->description,
            'amount' => $this->amount->grosze,
            'evidence' => $this->evidence->value,
            'certainty' => $this->evidence->certainty()->value,
            'reference' => $this->reference,
        ];
    }
}
