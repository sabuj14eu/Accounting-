<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;

/** `DaneFaKorygowanej` and the correction fields for `RodzajFaktury = KOR`. */
final class Fa3Correction
{
    public function __construct(
        public readonly string $reason,
        public readonly DateTimeImmutable $correctedIssueDate,
        public readonly string $correctedInvoiceNumber,
        /** The KSeF number of the corrected invoice; null means it was issued outside KSeF (NrKSeFN=1). */
        public readonly ?string $correctedKsefNumber = null,
        /** TTypKorekty 1|2|3, optional. */
        public readonly ?string $effectType = null,
    ) {
        if (trim($reason) === '' || mb_strlen($reason) > 256) {
            throw new \InvalidArgumentException('Przyczyna korekty jest wymagana (1–256 znaków).');
        }
        if (trim($correctedInvoiceNumber) === '' || mb_strlen($correctedInvoiceNumber) > 256) {
            throw new \InvalidArgumentException('Numer faktury korygowanej jest wymagany.');
        }
        if ($effectType !== null && ! in_array($effectType, ['1', '2', '3'], true)) {
            throw new \InvalidArgumentException('TypKorekty musi być 1, 2 lub 3.');
        }
    }
}
