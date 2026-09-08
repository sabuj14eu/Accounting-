<?php

declare(strict_types=1);

namespace Poland\Ksef\Crypto;

use Poland\Ksef\Secret;

/**
 * The symmetric key of one interactive session: the AES key and IV kept as
 * secrets in memory, and the wrapped form that goes to KSeF.
 */
final class EncryptionMaterial
{
    public function __construct(
        public readonly Secret $aesKey,
        public readonly Secret $iv,
        public readonly string $encryptedSymmetricKeyBase64,
        public readonly string $initializationVectorBase64,
        public readonly string $publicKeyId,
    ) {
    }
}
