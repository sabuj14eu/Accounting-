<?php

declare(strict_types=1);

namespace Poland\Ksef\Parsing;

use DateTimeImmutable;

/**
 * The `Platnosc` node of an FA invoice: when and how the issuer expects to be paid.
 *
 * `paidOnInvoice` is NULL when the element is absent. Absence is not "unpaid":
 * many issuers simply omit the node, and reading that as an open receivable
 * would send the bank matcher chasing a cash purchase forever.
 */
final class PaymentTerms implements \JsonSerializable
{
    /** FA `FormaPlatnosci` codes, per the schema's own enumeration. */
    public const FORMS = [
        '1' => 'gotówka',
        '2' => 'karta',
        '3' => 'bon',
        '4' => 'czek',
        '5' => 'kredyt',
        '6' => 'przelew',
        '7' => 'mobilna',
    ];

    /** @param array<string,mixed> $raw */
    public function __construct(
        public readonly ?DateTimeImmutable $dueDate,
        public readonly ?string $paymentForm,
        public readonly ?bool $paidOnInvoice,
        public readonly ?DateTimeImmutable $paymentDate,
        public readonly ?string $supplierAccount,
        public readonly array $raw = [],
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null, null, null, []);
    }

    public function isPresent(): bool
    {
        return $this->raw !== [];
    }

    public function paymentFormLabel(): ?string
    {
        if ($this->paymentForm === null) {
            return null;
        }

        return self::FORMS[$this->paymentForm] ?? ('kod '.$this->paymentForm);
    }

    /** Paid at the counter: cash or card, or the issuer says it is settled. */
    public function settledAtIssue(): bool
    {
        return $this->paidOnInvoice === true || in_array($this->paymentForm, ['1', '2'], true);
    }

    public function jsonSerialize(): array
    {
        return [
            'due_date' => $this->dueDate?->format('Y-m-d'),
            'payment_form' => $this->paymentForm,
            'payment_form_label' => $this->paymentFormLabel(),
            'paid_on_invoice' => $this->paidOnInvoice,
            'payment_date' => $this->paymentDate?->format('Y-m-d'),
            'supplier_account' => $this->supplierAccount,
            'present' => $this->isPresent(),
        ];
    }
}
