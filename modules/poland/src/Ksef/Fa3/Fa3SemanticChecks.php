<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * Checks the schema cannot express, run BEFORE generation: an invoice that
 * fails one of these is BLOCKED with the reason, never sent for KSeF to
 * reject. None of them computes a tax figure; they compare figures the
 * accounting system already stated.
 */
final class Fa3SemanticChecks
{
    /** @return list<string> problems; empty means the invoice may be generated */
    public static function check(Fa3Invoice $invoice, DateTimeImmutable $today): array
    {
        $problems = [];

        if (! self::nipChecksum($invoice->seller->nip)) {
            $problems[] = sprintf('NIP sprzedawcy %s ma błędną sumę kontrolną.', $invoice->seller->nip);
        }
        if ($invoice->buyer->identifier->kind === Fa3BuyerIdentifier::NIP && ! self::nipChecksum((string) $invoice->buyer->identifier->value)) {
            $problems[] = sprintf('NIP nabywcy %s ma błędną sumę kontrolną.', (string) $invoice->buyer->identifier->value);
        }
        if ($invoice->issueDate->format('Y-m-d') > $today->format('Y-m-d')) {
            $problems[] = sprintf('Data wystawienia %s jest późniejsza niż dziś (%s); KSeF odrzuca faktury z przyszłą datą.', $invoice->issueDate->format('Y-m-d'), $today->format('Y-m-d'));
        }
        if ($invoice->hasExemptLines() && $invoice->exemptionLegalBasis === null) {
            $problems[] = 'Faktura ma pozycje zwolnione (zw) bez podstawy prawnej zwolnienia (P_19A). Uzupełnij podstawę w ustawieniach KSeF.';
        }
        if (! $invoice->hasExemptLines() && $invoice->exemptionLegalBasis !== null) {
            // Not an error — the basis is simply not emitted. Recorded for transparency.
        }

        foreach ($invoice->lines as $line) {
            if ($line->quantity !== null && $line->unitNetPrice !== null) {
                $expected = self::product($line->quantity, $line->unitNetPrice);
                if ($expected !== null && abs($expected->grosze - $line->netValue->grosze) > 1) {
                    $problems[] = sprintf(
                        'Pozycja %d: ilość × cena jednostkowa = %s, a wartość netto (P_11) = %s.',
                        $line->number,
                        $expected->format(''),
                        $line->netValue->format(''),
                    );
                }
            }
            $percent = $line->rate->percent();
            if ($percent !== null) {
                $expectedVat = $line->netValue->times($percent / 100);
                if (abs($expectedVat->grosze - $line->vatAmount->grosze) > 1) {
                    $problems[] = sprintf(
                        'Pozycja %d: VAT zaksięgowany %s nie odpowiada stawce %s%% od %s (oczekiwano ok. %s). System nie poprawia kwot — popraw fakturę w księgowości.',
                        $line->number,
                        $line->vatAmount->format(''),
                        $percent,
                        $line->netValue->format(''),
                        $expectedVat->format(''),
                    );
                }
            }
        }

        $totals = $invoice->totals();
        if ($totals->gross->isNegative() && $invoice->type !== Fa3Invoice::TYPE_KOR) {
            $problems[] = 'Kwota należności ogółem (P_15) jest ujemna na fakturze innej niż korygująca.';
        }

        return $problems;
    }

    /** Weighted checksum of a Polish NIP (weights 6 5 7 2 3 4 5 6 7, modulo 11). */
    public static function nipChecksum(string $nip): bool
    {
        $digits = preg_replace('/\D/', '', $nip) ?? '';
        if (strlen($digits) !== 10) {
            return false;
        }
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $w * (int) $digits[$i];
        }
        $check = $sum % 11;

        return $check !== 10 && $check === (int) $digits[9];
    }

    private static function product(string $quantity, string $unitPrice): ?Money
    {
        if (! is_numeric($quantity) || ! is_numeric($unitPrice)) {
            return null;
        }
        // Exact enough for a 1-grosz tolerance on invoice-sized numbers.
        $value = (float) $quantity * (float) $unitPrice;

        return Money::parse(number_format($value, 2, '.', ''));
    }
}
