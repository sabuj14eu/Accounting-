<?php

declare(strict_types=1);

namespace Poland\Ksef;

/**
 * The 35-character KSeF invoice number and its CRC-8 check digit.
 *
 * Per `faktury/numer-ksef.md` of the pinned documentation: NIP (10) - date
 * (8) - technical part (12 hex) - CRC-8 (2 hex) over the 32 data characters,
 * polynomial 0x07, initial value 0x00. The Ministry's own XSD pattern also
 * admits `M` + 9 digits and three letters + 7 digits as the first segment
 * (internal and VAT-UE identifiers), so the format check follows the XSD and
 * the CRC check follows the guide.
 */
final class KsefNumber
{
    public const PATTERN = '/^([1-9](\d[1-9]|[1-9]\d)\d{7}|M\d{9}|[A-Z]{3}\d{7})-(20[2-9]\d|2[1-9]\d{2}|[3-9]\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])-([0-9A-F]{6})-?([0-9A-F]{6})-([0-9A-F]{2})$/';

    public static function isValid(string $number): bool
    {
        return self::reject($number) === null;
    }

    /** Why the number is not a KSeF number, or null when it is one. */
    public static function reject(string $number): ?string
    {
        if (preg_match(self::PATTERN, $number) !== 1) {
            return 'niezgodny z formatem numeru KSeF';
        }

        $data = substr($number, 0, -3); // strip "-FF"
        $expected = strtoupper(substr($number, -2));
        $actual = self::crc8($data);

        return $actual === $expected ? null : sprintf('suma kontrolna %s, oczekiwano %s', $expected, $actual);
    }

    /** CRC-8, polynomial 0x07, init 0x00, no reflection, no final xor. */
    public static function crc8(string $data): string
    {
        $crc = 0x00;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x80) !== 0 ? (($crc << 1) ^ 0x07) & 0xFF : ($crc << 1) & 0xFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 2, '0', STR_PAD_LEFT));
    }

    /** The seller NIP encoded in the number, when the first segment is a NIP. */
    public static function sellerNip(string $number): ?string
    {
        $first = explode('-', $number)[0] ?? '';

        return preg_match('/^\d{10}$/', $first) === 1 ? $first : null;
    }
}
