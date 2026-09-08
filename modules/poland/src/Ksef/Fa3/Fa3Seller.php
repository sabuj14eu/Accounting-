<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

/** `Podmiot1`: the taxpayer issuing the invoice. */
final class Fa3Seller
{
    public function __construct(
        public readonly string $nip,
        public readonly string $name,
        public readonly Fa3Address $address,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
    ) {
        if (preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/', $nip) !== 1) {
            throw new \InvalidArgumentException('NIP sprzedawcy nie ma poprawnego formatu (10 cyfr, wzorzec TNrNIP).');
        }
        if (trim($name) === '' || mb_strlen($name) > 512) {
            throw new \InvalidArgumentException('Nazwa sprzedawcy jest wymagana (1–512 znaków).');
        }
    }
}
