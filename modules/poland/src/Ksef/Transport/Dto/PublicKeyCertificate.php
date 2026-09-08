<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

use DateTimeImmutable;

/** One entry of GET /security/public-key-certificates. */
final class PublicKeyCertificate
{
    /** @param list<string> $usage */
    public function __construct(
        public readonly string $certificateBase64,
        public readonly string $certificateId,
        public readonly string $publicKeyId,
        public readonly DateTimeImmutable $validFrom,
        public readonly DateTimeImmutable $validTo,
        public readonly array $usage,
    ) {
    }

    public function certificateDer(): string
    {
        $der = base64_decode($this->certificateBase64, true);
        if ($der === false || $der === '') {
            throw new \RuntimeException('Certyfikat KSeF nie jest poprawnym Base64.');
        }

        return $der;
    }
}
