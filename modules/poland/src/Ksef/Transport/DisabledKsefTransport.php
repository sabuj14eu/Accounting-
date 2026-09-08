<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport;

use Poland\Ksef\Error\KsefException;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\AccessTokens;
use Poland\Ksef\Transport\Dto\AuthChallenge;
use Poland\Ksef\Transport\Dto\AuthInitiated;
use Poland\Ksef\Transport\Dto\AuthStatus;
use Poland\Ksef\Transport\Dto\InvoiceMetadataPage;
use Poland\Ksef\Transport\Dto\InvoiceQuery;
use Poland\Ksef\Transport\Dto\OpenedSession;
use Poland\Ksef\Transport\Dto\SessionInvoicesPage;
use Poland\Ksef\Transport\Dto\SessionInvoiceStatus;
use Poland\Ksef\Transport\Dto\SessionStatus;
use Poland\Ksef\Transport\Dto\TokenInfo;

/**
 * The transport bound when the integration is off. Every method throws
 * INTEGRATION_DISABLED. Nothing here can return an empty page, because an
 * empty page reads as "no invoices" and this transport knows nothing about
 * the taxpayer's invoices.
 */
final class DisabledKsefTransport implements KsefTransport
{
    public function __construct(
        private readonly KsefEnvironment $environment = KsefEnvironment::Test,
        private readonly string $why = 'KSEF_TRANSPORT=disabled lub KSEF_TRANSPORT_ENABLED=false.',
    ) {
    }

    public function kind(): string
    {
        return self::KIND_DISABLED;
    }

    public function environment(): KsefEnvironment
    {
        return $this->environment;
    }

    private function refuse(string $operation): never
    {
        throw KsefException::disabled($operation, $this->why);
    }

    public function publicKeyCertificates(): array
    {
        $this->refuse('publicKeyCertificates');
    }

    public function authChallenge(): AuthChallenge
    {
        $this->refuse('authChallenge');
    }

    public function authWithKsefToken(string $challenge, string $contextNip, string $encryptedTokenBase64, string $publicKeyId): AuthInitiated
    {
        $this->refuse('authWithKsefToken');
    }

    public function authStatus(string $referenceNumber, Secret $authenticationToken): AuthStatus
    {
        $this->refuse('authStatus');
    }

    public function redeemTokens(Secret $authenticationToken): AccessTokens
    {
        $this->refuse('redeemTokens');
    }

    public function refreshAccessToken(Secret $refreshToken): TokenInfo
    {
        $this->refuse('refreshAccessToken');
    }

    public function revokeCurrentSession(Secret $accessToken): void
    {
        $this->refuse('revokeCurrentSession');
    }

    public function openOnlineSession(Secret $accessToken, array $formCode, string $encryptedSymmetricKeyBase64, string $initializationVectorBase64, string $publicKeyId): OpenedSession
    {
        $this->refuse('openOnlineSession');
    }

    public function sendInvoice(Secret $accessToken, string $sessionReference, string $invoiceHashBase64, int $invoiceSize, string $encryptedInvoiceHashBase64, int $encryptedInvoiceSize, string $encryptedInvoiceContentBase64): string
    {
        $this->refuse('sendInvoice');
    }

    public function closeOnlineSession(Secret $accessToken, string $sessionReference): void
    {
        $this->refuse('closeOnlineSession');
    }

    public function sessionStatus(Secret $accessToken, string $sessionReference): SessionStatus
    {
        $this->refuse('sessionStatus');
    }

    public function sessionInvoices(Secret $accessToken, string $sessionReference, ?string $continuationToken = null, int $pageSize = 50): SessionInvoicesPage
    {
        $this->refuse('sessionInvoices');
    }

    public function sessionInvoiceStatus(Secret $accessToken, string $sessionReference, string $invoiceReference): SessionInvoiceStatus
    {
        $this->refuse('sessionInvoiceStatus');
    }

    public function sessionInvoiceUpo(Secret $accessToken, string $sessionReference, string $invoiceReference): string
    {
        $this->refuse('sessionInvoiceUpo');
    }

    public function sessionUpo(Secret $accessToken, string $sessionReference, string $upoReference): string
    {
        $this->refuse('sessionUpo');
    }

    public function queryInvoiceMetadata(Secret $accessToken, InvoiceQuery $query, int $pageOffset, int $pageSize, string $sortOrder = 'Asc'): InvoiceMetadataPage
    {
        $this->refuse('queryInvoiceMetadata');
    }

    public function invoiceXml(Secret $accessToken, string $ksefNumber): string
    {
        $this->refuse('invoiceXml');
    }

    public function rateLimits(Secret $accessToken): array
    {
        $this->refuse('rateLimits');
    }
}
