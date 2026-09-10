<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Auth\TokenEncryptor;
use Poland\Ksef\KsefNumber;

/**
 * The two pieces of the transport that have an external reference answer:
 * the KSeF number checksum (numbers quoted in the Ministry's own docs) and
 * RSA-OAEP/SHA-256 (decrypted by OpenSSL's implementation, not ours).
 */
final class KsefNumberAndEncryptionTest extends TestCase
{
    /** Numbers taken verbatim from CIRFMF/ksef-docs examples. */
    public function test_ksef_numbers_from_the_official_docs_validate(): void
    {
        foreach ([
            '5265877635-20250626-010080DD2B5E-26',
            '5265877635-20250925-010020A0A242-0A',
            '5555555555-20250828-010080615740-E4',
        ] as $number) {
            self::assertTrue(KsefNumber::isValid($number), $number);
        }
    }

    public function test_a_flipped_checksum_or_digit_is_rejected(): void
    {
        self::assertFalse(KsefNumber::isValid('5265877635-20250626-010080DD2B5E-27'));
        self::assertFalse(KsefNumber::isValid('5265877635-20250627-010080DD2B5E-26'));
        self::assertFalse(KsefNumber::isWellFormed('not-a-number'));
        self::assertFalse(KsefNumber::isWellFormed('5265877635-20250626-010080DD2B5E'));
    }

    public function test_the_36_character_ksef_1_form_is_accepted_for_reading(): void
    {
        $body = '5265877635-20250626-010080-DD2B5E';
        $number = $body.'-'.KsefNumber::crc8($body);
        self::assertTrue(KsefNumber::isWellFormed($number));
        self::assertTrue(KsefNumber::isValid($number));
    }

    public function test_synthetic_numbers_are_valid_and_deterministic(): void
    {
        $date = new \DateTimeImmutable('2026-09-10');
        $a = KsefNumber::synthetic('5265877635', $date, 'seed');
        $b = KsefNumber::synthetic('5265877635', $date, 'seed');
        $c = KsefNumber::synthetic('5265877635', $date, 'other');

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertTrue(KsefNumber::isValid($a));
        self::assertStringStartsWith('5265877635-20260910-', $a);
    }

    public function test_assert_valid_never_echoes_odd_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Numer KSeF "[0-9A-Za-z?\-…]*" ma niepoprawny/u');
        KsefNumber::assertValid("bad\x00\xff number");
    }

    public function test_oaep_encoding_matches_rfc_8017_layout(): void
    {
        $seed = str_repeat("\x11", 32);
        $em = TokenEncryptor::oaepEncode('abc', 256, $seed);

        self::assertSame(256, strlen($em));
        self::assertSame("\0", $em[0]);
        // Same seed, same message → identical encoding; different seed → different.
        self::assertSame($em, TokenEncryptor::oaepEncode('abc', 256, $seed));
        self::assertNotSame($em, TokenEncryptor::oaepEncode('abc', 256, str_repeat("\x22", 32)));
    }

    public function test_the_ciphertext_decrypts_with_openssl_oaep_sha256(): void
    {
        $openssl = trim((string) shell_exec('command -v openssl 2>/dev/null'));
        if ($openssl === '') {
            self::markTestSkipped('openssl CLI not available for the independent decryption');
        }

        $dir = sys_get_temp_dir().'/ksef-oaep-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($key);
            openssl_pkey_export_to_file($key, $dir.'/key.pem');
            $csr = openssl_csr_new(['commonName' => 'ksef-test'], $key, ['digest_alg' => 'sha256']);
            self::assertNotFalse($csr);
            $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
            self::assertNotFalse($cert);
            openssl_x509_export($cert, $pem);
            $der = base64_encode((string) base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem) ?? '', true));

            $token = 'ksef-token-'.bin2hex(random_bytes(8));
            $timestampMs = 1757500000000;
            $ciphertext = TokenEncryptor::encrypt($token, $timestampMs, $der);
            file_put_contents($dir.'/ct.bin', base64_decode($ciphertext, true));

            $out = shell_exec(sprintf(
                '%s pkeyutl -decrypt -inkey %s -in %s -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 2>&1',
                escapeshellarg($openssl),
                escapeshellarg($dir.'/key.pem'),
                escapeshellarg($dir.'/ct.bin'),
            ));

            self::assertSame($token.'|'.$timestampMs, $out);
            self::assertStringNotContainsString($token, $ciphertext);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function test_a_certificate_that_is_not_base64_is_reported_not_used(): void
    {
        $this->expectExceptionMessageMatches('/nie jest poprawnym base64/u');
        TokenEncryptor::encrypt('t', 1, '***not base64***');
    }
}
