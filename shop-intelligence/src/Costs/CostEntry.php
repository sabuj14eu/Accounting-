<?php

declare(strict_types=1);

namespace Shop\Costs;

use Shop\Truth\Certainty;
use Shop\Truth\Figure;
use Shop\Truth\Money;
use Shop\Truth\Provenance;

/**
 * One cost, with its category, its subcategory and — never optional — how it
 * got here.
 *
 * §16: a recurring cost is labelled ACTUAL once it is confirmed and EXPECTED
 * before that. Here that is not a label somebody sets; it follows from the
 * entry type, and confirm() is the only way to move a RECURRING cost to ACTUAL,
 * which requires the confirming evidence to be supplied.
 */
final class CostEntry implements \JsonSerializable
{
    private function __construct(
        public readonly string $reference,
        public readonly CostCategory $category,
        public readonly string $subcategory,
        public readonly Money $amount,
        public readonly CostEntryType $entryType,
        public readonly string $period,
        public readonly string $supplier = '',
        public readonly ?string $confirmedByReference = null,
    ) {
        if (trim($reference) === '') {
            throw new \InvalidArgumentException('A cost entry needs a reference.');
        }

        if (trim($subcategory) === '') {
            throw new \InvalidArgumentException("Cost {$reference} has no subcategory.");
        }
    }

    public static function record(
        string $reference,
        CostCategory $category,
        string $subcategory,
        Money $amount,
        CostEntryType $entryType,
        string $period,
        string $supplier = '',
    ): self {
        return new self($reference, $category, $subcategory, $amount, $entryType, $period, $supplier);
    }

    /**
     * A scheduled cost turns into a real one when something confirms it.
     *
     * The confirmation reference is mandatory: "mark as paid" with nothing
     * behind it is exactly the button that turns EXPECTED into ACTUAL by
     * wishful thinking.
     */
    public function confirmedBy(Money $actualAmount, CostEntryType $confirmingType, string $reference): self
    {
        if ($this->entryType !== CostEntryType::RECURRING) {
            throw new \LogicException('Only a recurring, expected cost is confirmed; the rest arrive confirmed.');
        }

        if (! in_array($confirmingType, [CostEntryType::BANK, CostEntryType::INVOICE], true)) {
            throw new \InvalidArgumentException(
                'A recurring cost becomes ACTUAL only on a bank line or an invoice. '
                .'A person typing "paid" leaves it USER_DECLARED, which is a different entry.'
            );
        }

        if (trim($reference) === '') {
            throw new \InvalidArgumentException('Confirmation requires the reference of the evidence.');
        }

        return new self(
            $this->reference,
            $this->category,
            $this->subcategory,
            $actualAmount,
            $confirmingType,
            $this->period,
            $this->supplier,
            $reference,
        );
    }

    public function figure(): Figure
    {
        return new Figure(
            $this->amount,
            $this->entryType->evidence(),
            $this->category->label().' / '.$this->subcategory
                .($this->supplier === '' ? '' : ' — '.$this->supplier),
            $this->confirmedByReference ?? $this->reference,
            $this->period,
        );
    }

    public function certainty(): Certainty
    {
        return $this->entryType->evidence()->certainty();
    }

    public function provenance(): Provenance
    {
        return $this->entryType->evidence()->provenance();
    }

    public function isActual(): bool
    {
        return $this->certainty() === Certainty::ACTUAL;
    }

    public function isExpected(): bool
    {
        return $this->certainty() === Certainty::EXPECTED;
    }

    public function jsonSerialize(): array
    {
        return [
            'reference' => $this->reference,
            'category' => $this->category->value,
            'subcategory' => $this->subcategory,
            'amount' => $this->amount->grosze,
            'entry_type' => $this->entryType->value,
            'certainty' => $this->certainty()->value,
            'provenance' => $this->provenance()->value,
            'period' => $this->period,
            'supplier' => $this->supplier,
            'confirmed_by_reference' => $this->confirmedByReference,
        ];
    }
}
