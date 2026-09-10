<?php

declare(strict_types=1);

namespace Poland\Ksef\Auth;

use Poland\Ksef\Http\KsefTransportException;

/**
 * RSA-OAEP (SHA-256, MGF1-SHA-256) encryption of `token|timestampMs` with the
 * Ministry's published public key — what POST /auth/ksef-token expects.
 *
 * PHP's openssl_public_encrypt only offers OAEP with SHA-1, so the OAEP
 * encoding (RFC 8017 §7.1.1) is done here and the RSA primitive is applied
 * with no padding. The unit test decrypts the result with `openssl pkeyutl`
 * configured for OAEP/SHA-256, i.e. with an implementation that is not this
 * one.
 *
 * Nothing here logs, stores or returns the plaintext.
 */
final class TokenEncryptor
{
    private const HASH = 'sha256';

    /**
     * @param string $certificateDerBase64 the `certificate` field of
     *        GET /security/public-key-certificates, DER, base64
     *
     * @return string base64 ciphertext for the `encryptedToken` field
     */
    public static function encrypt(string $ksefToken, int $timestampMs, string $certificateDerBase64): string
    {
        $pem = self::pemFromDerBase64($certificateDerBase64);
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw KsefTransportException::malformed('certyfikat klucza publicznego KSeF nie daje się odczytać');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ! isset($details['bits'])) {
            throw KsefTransportException::malformed('klucz publiczny KSeF nie jest kluczem RSA');
        }

        $plaintext = $ksefToken.'|'.$timestampMs;
        $encoded = self::oaepEncode($plaintext, intdiv((int) $details['bits'], 8));

        $ciphertext = '';
        if (! openssl_public_encrypt($encoded, $ciphertext, $key, OPENSSL_NO_PADDING)) {
            throw KsefTransportException::malformed('szyfrowanie tokenu kluczem KSeF nie powiodło się');
        }

        return base64_encode($ciphertext);
    }

    public static function pemFromDerBase64(string $derBase64): string
    {
        $clean = preg_replace('/\s+/', '', $derBase64) ?? '';
        if ($clean === '' || base64_decode($clean, true) === false) {
            throw KsefTransportException::malformed('certyfikat KSeF nie jest poprawnym base64');
        }

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($clean, 64, "\n").'-----END CERTIFICATE-----'."\n";
    }

    /** RFC 8017 §7.1.1 EME-OAEP encoding with an empty label. */
    public static function oaepEncode(string $message, int $k, ?string $seed = null): string
    {
        $hLen = 32;
        $mLen = strlen($message);
        if ($mLen > $k - 2 * $hLen - 2) {
            throw KsefTransportException::malformed('token za długi dla klucza KSeF');
        }

        $lHash = hash(self::HASH, '', true);
        $ps = str_repeat("\0", $k - $mLen - 2 * $hLen - 2);
        $db = $lHash.$ps."\x01".$message;

        $seed ??= random_bytes($hLen);
        $dbMask = self::mgf1($seed, $k - $hLen - 1);
        $maskedDb = $db ^ $dbMask;
        $seedMask = self::mgf1($maskedDb, $hLen);
        $maskedSeed = $seed ^ $seedMask;

        return "\0".$maskedSeed.$maskedDb;
    }

    private static function mgf1(string $seed, int $length): string
    {
        $out = '';
        for ($counter = 0; strlen($out) < $length; $counter++) {
            $out .= hash(self::HASH, $seed.pack('N', $counter), true);
        }

        return substr($out, 0, $length);
    }
}
