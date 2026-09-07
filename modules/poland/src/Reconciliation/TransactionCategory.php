<?php

declare(strict_types=1);

namespace Poland\Reconciliation;

/** What a bank transaction turned out to be. */
enum TransactionCategory: string
{
    case KsefInvoicePayment = 'ksef_invoice_payment';
    case Expense = 'expense';
    case Revenue = 'revenue';
    case ZusPayment = 'zus_payment';
    case TaxPayment = 'tax_payment';
    case Recurring = 'recurring';
    case Transfer = 'internal_transfer';

    /**
     * Not a category — the absence of one.
     *
     * Kept as a case so an unclassified transaction has somewhere to live that
     * is visibly not a classification. Anything here is shown to a human and
     * never booked.
     */
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::KsefInvoicePayment => 'Zapłata za fakturę z KSeF',
            self::Expense => 'Koszt',
            self::Revenue => 'Przychód',
            self::ZusPayment => 'Składki ZUS',
            self::TaxPayment => 'Podatek',
            self::Recurring => 'Płatność cykliczna',
            self::Transfer => 'Przelew własny',
            self::NeedsReview => 'DO PRZEGLĄDU',
        };
    }

    /** Whether a transaction in this category may be posted without a human. */
    public function isBookable(): bool
    {
        return $this !== self::NeedsReview;
    }
}
