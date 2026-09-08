<?php

declare(strict_types=1);

namespace Poland\Ksef\Crypto;

/**
 * RSAES-OAEP encryption with SHA-256 and MGF1-SHA-256, empty label
 * (RFC 8017 §7.1.1), on a public key given as (n, e).
 *
 * Written out because the contract requires SHA-256 OAEP and PHP's
 * `openssl_public_encrypt` only offers the SHA-1 variant. The seed is
 * injectable so a test can reproduce a ciphertext exactly; production always
 * uses `random_bytes`.
 */
final class RsaOaep
{
    private const HASH = 'sha256';

    private const HASH_LENGTH = 32;

    public function __construct(
        private readonly string $modulusBinary,
        private readonly string $publicExponentBinary,
    ) {
        if (ltrim($modulusBinary, "\0") === '' || ltrim($publicExponentBinary, "\0") === '') {
            throw new \InvalidArgumentException('RSA public key is empty.');
        }
    }

    /** From an X.509 certificate in DER (as KSeF publishes it, Base64-decoded). */
    public static function fromDerCertificate(string $der): self
    {
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END CERTIFICATE-----';
        $cert = @openssl_x509_read($pem);
        if ($cert === false) {
            throw new \RuntimeException('Nie udało się odczytać certyfikatu klucza publicznego KSeF.');
        }
        $key = @openssl_pkey_get_public($cert);
        if ($key === false) {
            throw new \RuntimeException('Certyfikat KSeF nie zawiera czytelnego klucza publicznego.');
        }
        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException(
                'Klucz publiczny KSeF nie jest kluczem RSA. Ta implementacja obsługuje wyłącznie RSA-OAEP; '
                .'wariant ECDsa wymaga osobnej, świadomej implementacji.',
            );
        }

        return new self($details['rsa']['n'], $details['rsa']['e']);
    }

    /** Length of the modulus in bytes (k in RFC 8017). */
    public function keyBytes(): int
    {
        return strlen(ltrim($this->modulusBinary, "\0"));
    }

    public function maxMessageBytes(): int
    {
        return $this->keyBytes() - 2 * self::HASH_LENGTH - 2;
    }

    /**
     * @param string|null $seed exactly 32 random bytes; null = random_bytes(32)
     * @return string raw ciphertext, exactly keyBytes() long
     */
    public function encrypt(string $message, ?string $seed = null): string
    {
        $k = $this->keyBytes();
        $hLen = self::HASH_LENGTH;
        $mLen = strlen($message);
        if ($mLen > $k - 2 * $hLen - 2) {
            throw new \LengthException(sprintf('Message of %d bytes is too long for a %d-byte RSA key with OAEP-SHA256.', $mLen, $k));
        }
        $seed ??= random_bytes($hLen);
        if (strlen($seed) !== $hLen) {
            throw new \InvalidArgumentException('OAEP seed must be exactly 32 bytes.');
        }

        $lHash = hash(self::HASH, '', true);
        $ps = str_repeat("\0", $k - $mLen - 2 * $hLen - 2);
        $db = $lHash.$ps."\x01".$message;
        $dbMask = self::mgf1($seed, $k - $hLen - 1);
        $maskedDb = $db ^ $dbMask;
        $seedMask = self::mgf1($maskedDb, $hLen);
        $maskedSeed = $seed ^ $seedMask;
        $em = "\0".$maskedSeed.$maskedDb;

        $m = BigInt::fromBinary($em);
        $c = $m->powMod(BigInt::fromBinary($this->publicExponentBinary), BigInt::fromBinary($this->modulusBinary));

        return $c->toBinary($k);
    }

    /** MGF1 with SHA-256 (RFC 8017 appendix B.2.1). */
    public static function mgf1(string $seed, int $length): string
    {
        $out = '';
        for ($counter = 0; strlen($out) < $length; $counter++) {
            $out .= hash(self::HASH, $seed.pack('N', $counter), true);
        }

        return substr($out, 0, $length);
    }
}
