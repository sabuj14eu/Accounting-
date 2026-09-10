<?php

declare(strict_types=1);

namespace Poland\Ksef;

/**
 * The KSeF invoice number: `NIP-YYYYMMDD-XXXXXXXXXXXX-CC`, 35 characters,
 * where the last two hex digits are CRC-8 (poly 0x07, init 0x00) of the
 * 32 characters before the final dash. The 36-character KSeF 1.0 form has a
 * dash inside the hex block and is accepted for reading.
 *
 * Checked before a number is put into a URL and after one comes back in
 * metadata, so a malformed listing is reported instead of being fetched or
 * stored as an identity.
 */
final class KsefNumber
{
    public const PATTERN = '/^([1-9](\d[1-9]|[1-9]\d)\d{7})-(20[2-9][0-9]|2[1-9]\d{2}|[3-9]\d{3})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])-([0-9A-F]{6})-?([0-9A-F]{6})-([0-9A-F]{2})$/';

    public static function isWellFormed(string $number): bool
    {
        return preg_match(self::PATTERN, $number) === 1;
    }

    /** Format AND checksum. */
    public static function isValid(string $number): bool
    {
        if (! self::isWellFormed($number)) {
            return false;
        }
        $body = substr($number, 0, -3);
        $given = strtoupper(substr($number, -2));

        return self::crc8($body) === $given;
    }

    public static function assertValid(string $number): void
    {
        if (! self::isValid($number)) {
            throw new \InvalidArgumentException(sprintf(
                'Numer KSeF "%s" ma niepoprawny format lub sumę kontrolną.',
                self::redactForMessage($number),
            ));
        }
    }

    /** CRC-8/ATM-style: polynomial 0x07, init 0x00, no reflection, no xorout. */
    public static function crc8(string $data): string
    {
        $crc = 0;
        foreach (str_split($data) as $byte) {
            $crc ^= ord($byte);
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x80) !== 0 ? (($crc << 1) ^ 0x07) & 0xFF : ($crc << 1) & 0xFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 2, '0', STR_PAD_LEFT));
    }

    /**
     * A syntactically valid, checksum-correct number for fakes and tests.
     * Deterministic for the same inputs.
     */
    public static function synthetic(string $nip, \DateTimeInterface $date, string $seed): string
    {
        $digits = preg_replace('/\D/', '', $nip) ?? '';
        if (strlen($digits) !== 10) {
            $digits = '1111111111';
        }
        $hex = strtoupper(substr(hash('sha256', $seed), 0, 12));
        $body = $digits.'-'.$date->format('Ymd').'-'.$hex;

        return $body.'-'.self::crc8($body);
    }

    private static function redactForMessage(string $number): string
    {
        $safe = preg_replace('/[^0-9A-Za-z-]/', '?', $number) ?? '';

        return strlen($safe) > 40 ? substr($safe, 0, 40).'…' : $safe;
    }
}
