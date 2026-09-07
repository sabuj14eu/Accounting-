<?php

declare(strict_types=1);

namespace Poland\Reporting;

use DateTimeImmutable;
use Poland\Domain\Money;
use Poland\Domain\Period;

/**
 * One line of the month's payment checklist.
 *
 * `amount` is what must be paid. `surplus` is money owed the OTHER way — a VAT
 * excess of input over output. They are separate fields on purpose: a surplus
 * rendered in an amount column becomes a payment the taxpayer makes by mistake,
 * which is the specific error this class exists to prevent.
 */
final class PaymentObligation implements \JsonSerializable
{
    public function __construct(
        public readonly ObligationKind $kind,
        public readonly Period $period,
        public readonly Money $amount,
        public readonly PaymentStatus $status,
        public readonly ?DateTimeImmutable $dueDate,
        public readonly bool $dueDateVerified = true,
        public readonly ?string $form = null,
        public readonly ?Money $surplus = null,
        public readonly ?DateTimeImmutable $paidAt = null,
        public readonly ?Money $amountPaid = null,
        public readonly ?string $paymentReference = null,
        public readonly ?string $notes = null,
    ) {
    }

    public function payTo(): string
    {
        return $this->kind->payTo();
    }

    public function hasSurplus(): bool
    {
        return $this->surplus !== null && $this->surplus->isPositive();
    }

    /** One line for a human: what this obligation actually means. */
    public function headline(): string
    {
        if ($this->hasSurplus()) {
            return sprintf('NADWYŻKA %s — nic do zapłaty', $this->surplus->format());
        }

        if ($this->status === PaymentStatus::NothingToPay) {
            return 'Nic do zapłaty';
        }

        if ($this->status === PaymentStatus::Paid) {
            return sprintf(
                'Zapłacone %s%s',
                $this->paidAt?->format('d.m.Y') ?? '',
                $this->paymentReference !== null ? ' · '.$this->paymentReference : '',
            );
        }

        return sprintf('Do zapłaty %s', $this->amount->format());
    }

    /** Difference between what was due and what was actually paid. */
    public function shortfall(): ?Money
    {
        if ($this->amountPaid === null) {
            return null;
        }

        return $this->amount->minus($this->amountPaid);
    }

    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind->value,
            'label' => $this->kind->label(),
            'form' => $this->form,
            'pay_to' => $this->payTo(),
            'period' => $this->period->toString(),
            'amount' => $this->amount,
            'surplus' => $this->surplus,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'headline' => $this->headline(),
            'due_date' => $this->dueDate?->format('Y-m-d'),
            'due_date_verified' => $this->dueDateVerified,
            'paid_at' => $this->paidAt?->format('Y-m-d'),
            'amount_paid' => $this->amountPaid,
            'payment_reference' => $this->paymentReference,
            'notes' => $this->notes,
        ];
    }
}
