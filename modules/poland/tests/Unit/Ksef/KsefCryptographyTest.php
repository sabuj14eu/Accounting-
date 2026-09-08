<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Crypto\BigInt;
use Poland\Ksef\Crypto\KsefCryptography;
use Poland\Ksef\Crypto\RsaOaep;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\PublicKeyCertificate;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Tests\Support\KsefFixtures;

/** The contract's cryptography: RSA-OAEP-SHA256, AES-256-CBC, certificate selection. */
final class KsefCryptographyTest extends TestCase
{
    public function test_bigint_arithmetic_agrees_with_native_integers(): void
    {
        mt_srand(7);
        for ($i = 0; $i < 300; $i++) {
            $a = mt_rand(0, PHP_INT_MAX >> 3);
            $m = mt_rand(1, 1 << 40);
            self::assertSame(0, BigInt::fromInt($a)->mod(BigInt::fromInt($m))->compare(BigInt::fromInt($a % $m)), "$a mod $m");
        }
        $product = BigInt::fromInt(123456789)->multiply(BigInt::fromInt(987654321));
        self::assertSame(dechex(123456789 * 987654321), ltrim(bin2hex($product->toBinary()), '0'));
        self::assertSame('01', bin2hex(BigInt::fromInt(3)->powMod(BigInt::fromInt(4), BigInt::fromInt(5))->toBinary())); // 81 mod 5
        self::assertSame(bin2hex(BigInt::fromInt(3 ** 7 % 1000)->toBinary()), bin2hex(BigInt::fromInt(3)->powMod(BigInt::fromInt(7), BigInt::fromInt(1000))->toBinary()));
    }

    public function test_rsa_oaep_sha256_round_trips_through_openssl(): void
    {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            self::markTestSkipped('openssl CLI not available: NOT RUNNABLE');
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);
        $rsa = new RsaOaep($details['rsa']['n'], $details['rsa']['e']);
        self::assertSame(256, $rsa->keyBytes());
        self::assertSame(190, $rsa->maxMessageBytes());

        $plain = 'token-abc|1725800000000';
        $cipher = $rsa->encrypt($plain);
        self::assertSame(256, strlen($cipher));
        self::assertNotSame($cipher, $rsa->encrypt($plain), 'a fresh random seed every time');

        $dir = sys_get_temp_dir().'/ksef-oaep-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        file_put_contents($dir.'/k.pem', $pem);
        file_put_contents($dir.'/c.bin', $cipher);
        $out = shell_exec(sprintf(
            'openssl pkeyutl -decrypt -inkey %s -in %s -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 2>&1',
            escapeshellarg($dir.'/k.pem'),
            escapeshellarg($dir.'/c.bin'),
        ));
        unlink($dir.'/k.pem');
        unlink($dir.'/c.bin');
        rmdir($dir);
        self::assertSame($plain, $out, 'OpenSSL must decrypt what the pure-PHP OAEP produced (SHA-256, MGF1-SHA-256)');
    }

    public function test_a_message_too_long_for_the_key_is_refused(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $this->expectException(\LengthException::class);
        (new RsaOaep($details['rsa']['n'], $details['rsa']['e']))->encrypt(str_repeat('x', 191));
    }

    public function test_the_ministry_certificate_is_read_from_der_and_used_for_the_token(): void
    {
        $certificate = KsefCryptography::select(FakeKsefTransport::defaultCertificates(KsefFixtures::now()), KsefCryptography::USAGE_TOKEN, KsefFixtures::now());
        $encrypted = KsefCryptography::encryptKsefToken(Secret::of('my-token'), 1725800000000, $certificate);
        $raw = base64_decode($encrypted, true);
        self::assertNotFalse($raw);
        self::assertSame(256, strlen($raw));
    }

    public function test_certificate_selection_prefers_the_latest_valid_one_for_the_usage(): void
    {
        $now = KsefFixtures::now();
        $old = new PublicKeyCertificate('AA==', 'old', 'KEY-OLD', $now->modify('-2 years'), $now->modify('+1 month'), ['SymmetricKeyEncryption']);
        $new = new PublicKeyCertificate('AA==', 'new', 'KEY-NEW', $now->modify('-1 day'), $now->modify('+2 years'), ['SymmetricKeyEncryption']);
        $future = new PublicKeyCertificate('AA==', 'future', 'KEY-FUTURE', $now->modify('+1 day'), $now->modify('+2 years'), ['SymmetricKeyEncryption']);
        $expired = new PublicKeyCertificate('AA==', 'expired', 'KEY-EXP', $now->modify('-3 years'), $now->modify('-1 day'), ['SymmetricKeyEncryption']);
        $token = new PublicKeyCertificate('AA==', 'tok', 'KEY-TOKEN', $now->modify('-1 day'), $now->modify('+2 years'), ['KsefTokenEncryption']);

        self::assertSame('KEY-NEW', KsefCryptography::select([$old, $new, $future, $expired, $token], 'SymmetricKeyEncryption', $now)->publicKeyId);
        self::assertSame('KEY-TOKEN', KsefCryptography::select([$old, $new, $token], 'KsefTokenEncryption', $now)->publicKeyId);

        $this->expectException(\RuntimeException::class);
        KsefCryptography::select([$expired, $future], 'SymmetricKeyEncryption', $now);
    }

    public function test_aes_256_cbc_with_pkcs7_round_trips_and_the_key_is_wrapped(): void
    {
        $certificate = KsefCryptography::select(FakeKsefTransport::defaultCertificates(KsefFixtures::now()), KsefCryptography::USAGE_SYMMETRIC, KsefFixtures::now());
        $material = KsefCryptography::newSessionMaterial($certificate);
        self::assertSame(32, strlen($material->aesKey->reveal()));
        self::assertSame(16, strlen($material->iv->reveal()));
        self::assertSame(16, strlen((string) base64_decode($material->initializationVectorBase64, true)));
        self::assertSame(256, strlen((string) base64_decode($material->encryptedSymmetricKeyBase64, true)));
        self::assertSame($certificate->publicKeyId, $material->publicKeyId);

        $xml = KsefFixtures::xml();
        $cipher = KsefCryptography::encryptInvoice($xml, $material);
        self::assertSame(0, strlen($cipher) % 16, 'PKCS#7 pads to the block size');
        self::assertSame($xml, KsefCryptography::decryptInvoice($cipher, $material));
        self::assertSame(base64_encode(hash('sha256', $xml, true)), KsefCryptography::sha256Base64($xml));
    }

    public function test_an_ec_certificate_is_refused_rather_than_misused(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => 'EC'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '', true) ?: '';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/RSA/');
        RsaOaep::fromDerCertificate($der);
    }
}
