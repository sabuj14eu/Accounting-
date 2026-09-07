<?php

declare(strict_types=1);

namespace Poland\Calculators;

use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Reporting\Breakdown;

/** The VAT position for one settlement period. */
final class VatSettlement implements \JsonSerializable
{
    public function __construct(
        public readonly Period $period,
        public readonly bool $settlesVat,
        /** VAT należny — output tax on sales. */
        public readonly Money $outputVat,
        /** VAT naliczony — input tax on purchases. */
        public readonly Money $inputVat,
        /** Positive: pay this. Zero when the balance is in the taxpayer's favour. */
        public readonly Money $amountToPay,
        /** Positive when input exceeds output (nadwyżka do przeniesienia lub zwrotu). */
        public readonly Money $carryForward,
        public readonly Breakdown $breakdown,
        /** @var list<string> */
        public readonly array $notes = [],
        public readonly ?string $jpkStructure = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period->toString(),
            'settles_vat' => $this->settlesVat,
            'output_vat' => $this->outputVat,
            'input_vat' => $this->inputVat,
            'amount_to_pay' => $this->amountToPay,
            'carry_forward' => $this->carryForward,
            'jpk_structure' => $this->jpkStructure,
            'notes' => $this->notes,
            'breakdown' => $this->breakdown,
        ];
    }
}
