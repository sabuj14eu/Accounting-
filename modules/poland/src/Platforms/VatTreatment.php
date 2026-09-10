<?php

declare(strict_types=1);

namespace Poland\Platforms;

use RuntimeException;

/**
 * How the platform's commission enters the VAT return.
 *
 * Decided by WHO issues the commission invoice and from WHERE — a fact about
 * the contract, not about the numbers, so it is told to the system (once,
 * confirmed with the accountant) and remembered. UNKNOWN records everything,
 * reconciles against the bank, and REFUSES to post to VAT.
 */
enum VatTreatment: string
{
    /** A Polish VAT taxpayer invoices the commission; it arrives via KSeF like any purchase. */
    case DomesticInvoice = 'domestic_invoice';

    /** A foreign entity invoices the commission; the shop self-assesses output VAT and deducts the same as input. */
    case ImportOfServices = 'import_of_services';

    /** Not yet decided. Records and reconciles; never posts. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::DomesticInvoice => 'faktura krajowa za prowizję (VAT naliczony z faktury w KSeF)',
            self::ImportOfServices => 'import usług (VAT należny i naliczony rozliczane przez nabywcę)',
            self::Unknown => 'NIEUSTALONE — nie księguje się do VAT',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Unknown;
    }

    public function assertPostable(string $subject): void
    {
        if ($this->isDecided()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Rozliczenie %s ma NIEUSTALONE traktowanie VAT prowizji. Zależy ono od tego, kto i skąd '
            .'wystawia fakturę za prowizję (podmiot krajowy → zwykły zakup; zagraniczny → import usług) '
            .'i zmienia wiersze deklaracji. Potwierdź z księgowym i zapisz decyzję — do tego czasu '
            .'rozliczenie jest zapisane i uzgodnione z bankiem, ale NIE wchodzi do VAT.',
            $subject,
        ));
    }
}
