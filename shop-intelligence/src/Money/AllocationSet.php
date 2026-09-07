<?php

declare(strict_types=1);

namespace Shop\Money;

use Shop\Truth\EvidenceType;
use Shop\Truth\Figure;
use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;
use Shop\Truth\Total;

/**
 * Everything that has been put against one document — §5.
 *
 * The worked example from the specification: a 5 000 zł supplier invoice, of
 * which 3 000 is visible on the bank statement and 2 000 the owner says was
 * paid in cash. The invoice IS fully allocated: it is not outstanding and the
 * owner should not be chased for it. But the two halves are not the same kind
 * of fact, and this class keeps them apart for the life of the record. The
 * state answers "do I still owe this?"; the evidence mix answers "how do I
 * know?", and no screen may show the first without the second.
 */
final class AllocationSet implements \JsonSerializable
{
    /** @var list<Figure> */
    private array $allocations = [];

    public function __construct(
        public readonly string $documentReference,
        public readonly Money $documentAmount,
        public readonly string $counterparty = '',
    ) {
        if (trim($documentReference) === '') {
            throw new \InvalidArgumentException('An allocation set must name its document.');
        }
    }

    public function allocate(Figure $payment): self
    {
        if ($payment->amount->isNegative()) {
            throw new \InvalidArgumentException('An allocation cannot be negative; record a credit note instead.');
        }

        if ($payment->evidence === EvidenceType::AI_SUGGESTED) {
            throw new \DomainException(
                'A model suggestion cannot settle a document. Route it through PendingSuggestion '
                .'and let a human accept it — §22: AI must not declare an invoice paid.'
            );
        }

        $this->allocations[] = $payment;

        return $this;
    }

    /** @return list<Figure> */
    public function allocations(): array
    {
        return $this->allocations;
    }

    public function allocated(): Total
    {
        return Total::of($this->allocations);
    }

    public function outstanding(): Money
    {
        $remaining = $this->documentAmount->minus($this->allocated()->amount);

        return $remaining->isNegative() ? Money::zero() : $remaining;
    }

    public function overAllocated(): Money
    {
        $excess = $this->allocated()->amount->minus($this->documentAmount);

        return $excess->isPositive() ? $excess : Money::zero();
    }

    public function state(): AllocationState
    {
        $allocated = $this->allocated()->amount;

        if ($allocated->isZero()) {
            return AllocationState::UNALLOCATED;
        }

        if ($allocated->greaterThan($this->documentAmount)) {
            return AllocationState::OVER_ALLOCATED;
        }

        return $allocated->equals($this->documentAmount)
            ? AllocationState::FULLY_ALLOCATED
            : AllocationState::PARTIALLY_ALLOCATED;
    }

    /** The part a bank, a terminal or a platform also confirms. */
    public function corroborated(): Money
    {
        return $this->allocated()->corroborated;
    }

    /** The part that rests on somebody's word — declared cash, above all. */
    public function uncorroborated(): Money
    {
        return $this->allocated()->uncorroborated;
    }

    /**
     * The sentence a settled-but-partly-declared document must be shown with.
     *
     * There is no method that returns "PAID" on its own.
     */
    public function settlementDescription(): string
    {
        $state = $this->state()->label();

        if ($this->allocations === []) {
            return $state.' — nothing recorded against '.$this->documentAmount->format();
        }

        $parts = [];
        foreach ($this->allocations as $allocation) {
            $parts[] = $allocation->amount->format().' '.$allocation->evidence->value
                .($allocation->isCorroborated() ? '' : ' (not independently confirmed)');
        }

        return $state.' — '.implode(' + ', $parts);
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        $flags = [];

        if ($this->state() === AllocationState::OVER_ALLOCATED) {
            $flags[] = new ReviewFlag(
                'OVER_ALLOCATED_DOCUMENT',
                $this->documentReference,
                'More has been allocated to this document than the document is for: excess '
                    .$this->overAllocated()->format().'.',
                [
                    'the same payment was matched twice, from two imports of the same statement',
                    'a payment covering several invoices was allocated to this one in full',
                    'the document amount was entered without a credit note that reduced it',
                    'a deposit paid earlier was allocated here as well as to its own document',
                ],
                ReviewFlag::SEVERITY_REVIEW,
                $this->overAllocated(),
            );
        }

        if ($this->state() === AllocationState::FULLY_ALLOCATED && $this->uncorroborated()->isPositive()) {
            $flags[] = new ReviewFlag(
                'SETTLED_PARTLY_ON_DECLARATION',
                $this->documentReference,
                'This document is fully allocated, but '.$this->uncorroborated()->format()
                    .' of it rests on a declaration rather than on a bank, terminal or platform record.',
                [
                    'the payment really was made in cash and no receipt was kept',
                    'the payment went out of a private account that is not imported',
                    'the bank statement covering that date has not been imported yet',
                ],
                ReviewFlag::SEVERITY_INFO,
                $this->uncorroborated(),
            );
        }

        return $flags;
    }

    public function jsonSerialize(): array
    {
        return [
            'document_reference' => $this->documentReference,
            'counterparty' => $this->counterparty,
            'document_amount' => $this->documentAmount->grosze,
            'state' => $this->state()->value,
            'allocated' => $this->allocated(),
            'outstanding' => $this->outstanding()->grosze,
            'settlement_description' => $this->settlementDescription(),
            'review_flags' => $this->reviewFlags(),
        ];
    }
}
