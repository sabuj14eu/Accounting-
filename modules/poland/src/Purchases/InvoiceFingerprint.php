<?php

declare(strict_types=1);

namespace Poland\Purchases;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * The SECOND duplicate wall.
 *
 * The first is the unique KSeF number. This one catches the same invoice
 * arriving under two KSeF numbers — a supplier re-issuing, or a document keyed
 * in by hand and later delivered by KSeF. Built from what a supplier cannot
 * accidentally vary: their NIP, the invoice number with separators stripped,
 * the invoice date and the gross amount.
 *
 * Returns null when any part is unknown: a fingerprint of "missing" would make
 * every incomplete invoice look like a duplicate of every other.
 */
final class InvoiceFingerprint
{
    public static function of(
        ?string $sellerNip,
        ?string $invoiceNumber,
        ?DateTimeImmutable $invoiceDate,
        ?Money $gross,
    ): ?string {
        $nip = $sellerNip === null ? '' : preg_replace('/\D/', '', $sellerNip);
        $number = $invoiceNumber === null ? '' : self::normaliseNumber($invoiceNumber);

        if ($nip === '' || $number === '' || $invoiceDate === null || $gross === null) {
            return null;
        }

        return hash('sha256', implode('|', [
            $nip,
            $number,
            $invoiceDate->format('Y-m-d'),
            (string) $gross->grosze,
        ]));
    }

    /** "FV/2026/08/417", "fv 2026-08-417" and "FV_2026_08_417" are one number. */
    public static function normaliseNumber(string $number): string
    {
        return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $number) ?? '');
    }
}
