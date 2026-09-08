<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

/**
 * The `xsd:choice` inside `TPodmiot2`: a Polish NIP, an EU VAT number with
 * its prefix, another identifier with an optional country, or an explicit
 * "no identifier" marker. "No identifier" is a stated fact (`BrakID=1`),
 * never a blank.
 */
final class Fa3BuyerIdentifier
{
    public const NIP = 'nip';

    public const VAT_UE = 'vat_ue';

    public const OTHER = 'other';

    public const NONE = 'none';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $value,
        public readonly ?string $countryCode,
    ) {
    }

    public static function nip(string $nip): self
    {
        $digits = preg_replace('/\D/', '', $nip) ?? '';
        if (preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/', $digits) !== 1) {
            throw new \InvalidArgumentException('NIP nabywcy nie ma poprawnego formatu.');
        }

        return new self(self::NIP, $digits, 'PL');
    }

    public static function vatUe(string $countryPrefix, string $number): self
    {
        if (preg_match('/^[A-Z]{2}$/', $countryPrefix) !== 1 || preg_match('/^(\d|[A-Z]|\+|\*){1,12}$/', $number) !== 1) {
            throw new \InvalidArgumentException('Numer VAT UE nabywcy: prefiks kraju (2 litery) i 1–12 znaków [0-9A-Z+*].');
        }

        return new self(self::VAT_UE, $number, $countryPrefix);
    }

    public static function other(string $identifier, ?string $countryCode = null): self
    {
        if (trim($identifier) === '' || mb_strlen($identifier) > 50) {
            throw new \InvalidArgumentException('Inny identyfikator nabywcy ma 1–50 znaków.');
        }

        return new self(self::OTHER, $identifier, $countryCode);
    }

    public static function none(): self
    {
        return new self(self::NONE, null, null);
    }
}
