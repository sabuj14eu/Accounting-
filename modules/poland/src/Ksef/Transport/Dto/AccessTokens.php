<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport\Dto;

final class AccessTokens
{
    public function __construct(
        public readonly TokenInfo $accessToken,
        public readonly TokenInfo $refreshToken,
    ) {
    }
}
