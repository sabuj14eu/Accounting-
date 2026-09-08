<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * One `FaWiersz`. Amounts come from the accounting invoice as it was booked;
 * the mapper never computes tax, it only carries the ERP's figures and
 * checks they agree with each other.
 */
final class Fa3Line
{
    public function __construct(
        public readonly int $number,
        public readonly string $description,
        public readonly Fa3VatRate $rate,
        /** P_11: net value of the line. */
        public readonly Money $netValue,
        /** The VAT the accounting system booked for this line; feeds the totals only. */
        public readonly Money $vatAmount,
        /** P_8B, decimal string with up to 6 fraction digits. */
        public readonly ?string $quantity = null,
        /** P_8A */
        public readonly ?string $unit = null,
        /** P_9A, decimal string with up to 8 fraction digits. */
        public readonly ?string $unitNetPrice = null,
        /** P_6A */
        public readonly ?DateTimeImmutable $saleDate = null,
        /** Indeks */
        public readonly ?string $internalCode = null,
        /** GTU_01..GTU_13 */
        public readonly ?string $gtu = null,
    ) {
        if ($number < 1) {
            throw new \InvalidArgumentException('NrWierszaFa zaczyna się od 1.');
        }
        if (trim($description) === '' || mb_strlen($description) > 512) {
            throw new \InvalidArgumentException('Nazwa towaru/usługi (P_7) jest wymagana, 1–512 znaków.');
        }
        if ($quantity !== null && preg_match('/^-?([1-9]\d{0,15}|0)(\.\d{1,6})?$/', $quantity) !== 1) {
            throw new \InvalidArgumentException('Ilość (P_8B) musi być liczbą dziesiętną z maksymalnie 6 miejscami po kropce.');
        }
        if ($unitNetPrice !== null && preg_match('/^-?([1-9]\d{0,13}|0)(\.\d{1,8})?$/', $unitNetPrice) !== 1) {
            throw new \InvalidArgumentException('Cena jednostkowa (P_9A) musi być liczbą dziesiętną z maksymalnie 8 miejscami po kropce.');
        }
        if ($unit !== null && (trim($unit) === '' || mb_strlen($unit) > 256)) {
            throw new \InvalidArgumentException('Jednostka miary (P_8A) ma 1–256 znaków.');
        }
        if ($gtu !== null && preg_match('/^GTU_(0[1-9]|1[0-3])$/', $gtu) !== 1) {
            throw new \InvalidArgumentException('GTU musi mieć postać GTU_01..GTU_13.');
        }
        if (! $rate->taxesVat() && ! $vatAmount->isZero()) {
            throw new \InvalidArgumentException(sprintf('Wiersz %d ma stawkę "%s" i niezerowy VAT — sprzeczność.', $number, $rate->value));
        }
    }
}
