<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

/**
 * A plain, framework-free picture of an accounting invoice at the moment it
 * is prepared for KSeF. The Laravel layer builds it from the ERP's models
 * and the KSeF settings; the mapper turns it into an {@see Fa3Invoice}.
 *
 * Every field is what the accounting system stated. Nothing is derived here.
 */
final class ErpInvoiceSnapshot
{
    /**
     * @param array{nip: string, name: string, country: string, line1: string, line2?: ?string, email?: ?string, phone?: ?string} $seller
     * @param array{name: ?string, identifier_type: ?string, identifier_value?: ?string, identifier_country?: ?string, country?: ?string, line1?: ?string, line2?: ?string, email?: ?string, jst?: bool, gv?: bool} $buyer
     * @param list<array{description: string, quantity: string|int|float|null, unit?: ?string, unit_price: string|int|float|null, amount: string|int|float, tax_amount: string|int|float, tax_rate: string|int|float|null, zero_kind?: ?string, gtu?: ?string, code?: ?string}> $lines
     * @param array{reason: string, corrected_number: string, corrected_issue_date: string, corrected_ksef_number?: ?string, effect_type?: ?string}|null $correction
     */
    public function __construct(
        public readonly int|string $invoiceId,
        public readonly string $number,
        public readonly string $issueDate,
        public readonly array $seller,
        public readonly array $buyer,
        public readonly array $lines,
        public readonly string $currency = 'PLN',
        public readonly ?string $saleDate = null,
        public readonly ?string $dueDate = null,
        public readonly ?bool $paid = null,
        public readonly ?string $paidOn = null,
        public readonly ?string $paymentForm = null,
        public readonly ?string $bankAccount = null,
        public readonly ?string $bankName = null,
        public readonly ?string $issuePlace = null,
        public readonly ?string $exemptionLegalBasis = null,
        public readonly bool $vatExemptTaxpayer = false,
        public readonly bool $splitPayment = false,
        public readonly string $type = Fa3Invoice::TYPE_VAT,
        public readonly ?array $correction = null,
    ) {
    }
}
