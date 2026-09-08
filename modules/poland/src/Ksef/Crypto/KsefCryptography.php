<?php

declare(strict_types=1);

namespace Poland\Ksef\Crypto;

use DateTimeImmutable;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\PublicKeyCertificate;

/**
 * The three cryptographic operations the KSeF contract requires, and the
 * rule for choosing which Ministry certificate to use.
 */
final class KsefCryptography
{
    public const USAGE_TOKEN = 'KsefTokenEncryption';

    public const USAGE_SYMMETRIC = 'SymmetricKeyEncryption';

    /**
     * Pick the certificate for a usage: valid now, and among several valid
     * ones the one with the latest validFrom — exactly the rule in
     * `bezpieczenstwo/klucze-publiczne-do-szyfrowania.md` §4.1.
     *
     * @param list<PublicKeyCertificate> $certificates
     */
    public static function select(array $certificates, string $usage, DateTimeImmutable $now): PublicKeyCertificate
    {
        $candidates = array_filter(
            $certificates,
            static fn (PublicKeyCertificate $c): bool => in_array($usage, $c->usage, true)
                && $c->validFrom <= $now
                && $c->validTo >= $now,
        );
        if ($candidates === []) {
            throw new \RuntimeException(sprintf(
                'KSeF nie opublikował ważnego certyfikatu o przeznaczeniu %s (otrzymano %d certyfikatów). '
                .'Bez niego nie można zaszyfrować danych — operacja przerwana.',
                $usage,
                count($certificates),
            ));
        }
        usort($candidates, static fn (PublicKeyCertificate $a, PublicKeyCertificate $b): int => $b->validFrom <=> $a->validFrom);

        return array_values($candidates)[0];
    }

    /** `{token}|{timestampMs}` under RSA-OAEP-SHA256, Base64 — for POST /auth/ksef-token. */
    public static function encryptKsefToken(Secret $ksefToken, int $challengeTimestampMs, PublicKeyCertificate $certificate): string
    {
        $rsa = RsaOaep::fromDerCertificate($certificate->certificateDer());
        $plain = $ksefToken->reveal().'|'.$challengeTimestampMs;

        return base64_encode($rsa->encrypt($plain));
    }

    /** Fresh AES-256 key and IV for one session, with the key wrapped for KSeF. */
    public static function newSessionMaterial(PublicKeyCertificate $certificate): EncryptionMaterial
    {
        $key = random_bytes(32);
        $iv = random_bytes(16);
        $rsa = RsaOaep::fromDerCertificate($certificate->certificateDer());

        return new EncryptionMaterial(
            Secret::of($key),
            Secret::of($iv),
            base64_encode($rsa->encrypt($key)),
            base64_encode($iv),
            $certificate->publicKeyId,
        );
    }

    /** AES-256-CBC with PKCS#7 padding, as the contract requires for invoice bodies. */
    public static function encryptInvoice(string $xml, EncryptionMaterial $material): string
    {
        $cipher = openssl_encrypt($xml, 'aes-256-cbc', $material->aesKey->reveal(), OPENSSL_RAW_DATA, $material->iv->reveal());
        if ($cipher === false) {
            throw new \RuntimeException('Szyfrowanie AES-256-CBC nie powiodło się.');
        }

        return $cipher;
    }

    /** Used only by tests and the recovery path: decrypts what encryptInvoice produced. */
    public static function decryptInvoice(string $cipher, EncryptionMaterial $material): string
    {
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $material->aesKey->reveal(), OPENSSL_RAW_DATA, $material->iv->reveal());
        if ($plain === false) {
            throw new \RuntimeException('Deszyfrowanie AES-256-CBC nie powiodło się.');
        }

        return $plain;
    }

    /** SHA-256 of a document, Base64 — the form KSeF uses in every hash field. */
    public static function sha256Base64(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }
}
