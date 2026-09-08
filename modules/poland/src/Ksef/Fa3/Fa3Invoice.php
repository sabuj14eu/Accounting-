<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;

/**
 * Everything the FA(3) generator needs, and nothing it must invent.
 *
 * Every field has a single origin in the accounting system: the header from
 * the invoice row, the seller from the tax profile and the KSeF settings, the
 * buyer from the customer, the lines from the invoice items. Optional fields
 * that are absent stay absent — the generator emits no element for them, it
 * never defaults a value.
 */
final class Fa3Invoice
{
    public const TYPE_VAT = 'VAT';

    public const TYPE_KOR = 'KOR';

    /**
     * @param list<Fa3Line> $lines
     */
    public function __construct(
        public readonly Fa3Seller $seller,
        public readonly Fa3Buyer $buyer,
        public readonly string $currency,
        /** P_1 */
        public readonly DateTimeImmutable $issueDate,
        /** P_2 */
        public readonly string $number,
        public readonly array $lines,
        /** P_6 */
        public readonly ?DateTimeImmutable $saleDate = null,
        /** P_1M */
        public readonly ?string $issuePlace = null,
        public readonly ?Fa3Payment $payment = null,
        public readonly string $type = self::TYPE_VAT,
        public readonly ?Fa3Correction $correction = null,
        /** P_19A: the legal basis of the exemption, required when any line is `zw`. */
        public readonly ?string $exemptionLegalBasis = null,
        /** P_18A: split payment ("mechanizm podzielonej płatności") applies. */
        public readonly bool $splitPayment = false,
        /** P_16: cash-basis ("metoda kasowa"). */
        public readonly bool $cashMethod = false,
        /** DodatkowyOpis lines, key => value, both 1–256 characters. @var array<string,string> */
        public readonly array $additionalDescriptions = [],
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Kod waluty musi mieć 3 wielkie litery (ISO 4217).');
        }
        if (trim($number) === '' || mb_strlen($number) > 256) {
            throw new \InvalidArgumentException('Numer faktury (P_2) jest wymagany, 1–256 znaków.');
        }
        if (! in_array($type, [self::TYPE_VAT, self::TYPE_KOR], true)) {
            throw new \InvalidArgumentException('Ta implementacja wystawia faktury VAT i KOR; inne rodzaje wymagają osobnej implementacji.');
        }
        if ($type === self::TYPE_KOR && $correction === null) {
            throw new \InvalidArgumentException('Faktura korygująca wymaga danych faktury korygowanej.');
        }
        if ($type === self::TYPE_VAT && $correction !== null) {
            throw new \InvalidArgumentException('Faktura VAT nie może nieść danych korekty.');
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('Faktura bez pozycji nie zostanie wystawiona.');
        }
        $expected = 1;
        foreach ($lines as $line) {
            if (! $line instanceof Fa3Line) {
                throw new \InvalidArgumentException('Pozycje muszą być obiektami Fa3Line.');
            }
            if ($line->number !== $expected) {
                throw new \InvalidArgumentException(sprintf('Numeracja pozycji musi być ciągła od 1 (oczekiwano %d, jest %d).', $expected, $line->number));
            }
            $expected++;
        }
        foreach ($additionalDescriptions as $key => $value) {
            if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 256 || trim($value) === '' || mb_strlen($value) > 256) {
                throw new \InvalidArgumentException('DodatkowyOpis: klucz i wartość po 1–256 znaków.');
            }
        }
        if ($exemptionLegalBasis !== null && (trim($exemptionLegalBasis) === '' || mb_strlen($exemptionLegalBasis) > 256)) {
            throw new \InvalidArgumentException('Podstawa zwolnienia (P_19A) ma 1–256 znaków.');
        }
    }

    public function totals(): Fa3Totals
    {
        return Fa3Totals::fromLines($this->lines);
    }

    public function hasExemptLines(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->rate->isExempt()) {
                return true;
            }
        }

        return false;
    }

    public function hasReverseChargeLines(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->rate === Fa3VatRate::ReverseCharge) {
                return true;
            }
        }

        return false;
    }
}
