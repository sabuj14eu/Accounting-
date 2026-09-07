<?php

declare(strict_types=1);

namespace Poland\Domain;

/**
 * A month's deductible costs and input VAT.
 *
 * Optional throughout the engine, and its ABSENCE is never read as zero cost.
 * A report built without a purchase register says so, because "no costs
 * recorded" and "no costs incurred" are different facts and only the taxpayer
 * knows which one is true.
 */
final class PurchaseRegister implements \JsonSerializable
{
    public function __construct(
        public readonly Period $period,
        /** Net cost deductible for income tax (KPiR column 13 equivalent). */
        public readonly Money $deductibleCostsNet,
        /** Input VAT eligible for deduction (VAT naliczony). */
        public readonly Money $deductibleInputVat,
        public readonly int $documentCount = 0,
        public readonly ?string $note = null,
    ) {
    }

    public static function empty(Period $period): self
    {
        return new self($period, Money::zero(), Money::zero(), 0, 'Brak zarejestrowanych kosztów.');
    }

    public function isEmpty(): bool
    {
        return $this->deductibleCostsNet->isZero() && $this->deductibleInputVat->isZero();
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period->toString(),
            'deductible_costs_net' => $this->deductibleCostsNet,
            'deductible_input_vat' => $this->deductibleInputVat,
            'document_count' => $this->documentCount,
            'note' => $this->note,
        ];
    }
}
