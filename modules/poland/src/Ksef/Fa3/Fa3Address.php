<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

/** `TAdres`: country code, address line 1, optional line 2. */
final class Fa3Address
{
    public function __construct(
        public readonly string $countryCode,
        public readonly string $line1,
        public readonly ?string $line2 = null,
    ) {
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new \InvalidArgumentException('Kod kraju musi mieć dwie wielkie litery (ISO 3166-1).');
        }
        if (trim($line1) === '' || mb_strlen($line1) > 512) {
            throw new \InvalidArgumentException('AdresL1 jest wymagany i ma maksymalnie 512 znaków.');
        }
        if ($line2 !== null && (trim($line2) === '' || mb_strlen($line2) > 512)) {
            throw new \InvalidArgumentException('AdresL2, jeśli podany, ma 1–512 znaków.');
        }
    }
}
