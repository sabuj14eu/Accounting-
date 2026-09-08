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
use Poland\Ksef\Transport\Dto\PublicKeyCertificate;
use Poland\Ksef\Transport\Dto\SessionInvoicesPage;
use Poland\Ksef\Transport\Dto\SessionInvoiceStatus;
use Poland\Ksef\Transport\Dto\SessionStatus;
use Poland\Ksef\Transport\Dto\TokenInfo;

/**
 * The KSeF 2.0 API, one method per endpoint this application uses, named
 * after the endpoints in the pinned OpenAPI document (resources/ksef/PINNED.md).
 *
 * Every method either returns the typed, validated answer or throws a
 * {@see KsefException}. None of them ever returns an empty result to mean
 * "it failed" — an empty page from KSeF is a fact about the taxpayer's
 * invoices, and a failure is not.
 */
interface KsefTransport
{
    public const KIND_REAL = 'real';

    public const KIND_FAKE = 'fake';

    public const KIND_DISABLED = 'disabled';

    public function kind(): string;

    public function environment(): KsefEnvironment;

    /** GET /security/public-key-certificates @return list<PublicKeyCertificate> */
    public function publicKeyCertificates(): array;

    /** POST /auth/challenge */
    public function authChallenge(): AuthChallenge;

    /** POST /auth/ksef-token */
    public function authWithKsefToken(string $challenge, string $contextNip, string $encryptedTokenBase64, string $publicKeyId): AuthInitiated;

    /** GET /auth/{referenceNumber} */
    public function authStatus(string $referenceNumber, Secret $authenticationToken): AuthStatus;

    /** POST /auth/token/redeem — one-shot */
    public function redeemTokens(Secret $authenticationToken): AccessTokens;

    /** POST /auth/token/refresh */
    public function refreshAccessToken(Secret $refreshToken): TokenInfo;

    /** DELETE /auth/sessions/current */
    public function revokeCurrentSession(Secret $accessToken): void;

    /**
     * POST /sessions/online
     *
     * @param array{systemCode: string, schemaVersion: string, value: string} $formCode
     */
    public function openOnlineSession(Secret $accessToken, array $formCode, string $encryptedSymmetricKeyBase64, string $initializationVectorBase64, string $publicKeyId): OpenedSession;

    /** POST /sessions/online/{ref}/invoices @return string the invoice reference number */
    public function sendInvoice(
        Secret $accessToken,
        string $sessionReference,
        string $invoiceHashBase64,
        int $invoiceSize,
        string $encryptedInvoiceHashBase64,
        int $encryptedInvoiceSize,
        string $encryptedInvoiceContentBase64,
    ): string;

    /** POST /sessions/online/{ref}/close */
    public function closeOnlineSession(Secret $accessToken, string $sessionReference): void;

    /** GET /sessions/{ref} */
    public function sessionStatus(Secret $accessToken, string $sessionReference): SessionStatus;

    /** GET /sessions/{ref}/invoices */
    public function sessionInvoices(Secret $accessToken, string $sessionReference, ?string $continuationToken = null, int $pageSize = 50): SessionInvoicesPage;

    /** GET /sessions/{ref}/invoices/{invoiceRef} */
    public function sessionInvoiceStatus(Secret $accessToken, string $sessionReference, string $invoiceReference): SessionInvoiceStatus;

    /** GET /sessions/{ref}/invoices/{invoiceRef}/upo @return string UPO XML */
    public function sessionInvoiceUpo(Secret $accessToken, string $sessionReference, string $invoiceReference): string;

    /** GET /sessions/{ref}/upo/{upoRef} @return string UPO XML */
    public function sessionUpo(Secret $accessToken, string $sessionReference, string $upoReference): string;

    /** POST /invoices/query/metadata */
    public function queryInvoiceMetadata(Secret $accessToken, InvoiceQuery $query, int $pageOffset, int $pageSize, string $sortOrder = 'Asc'): InvoiceMetadataPage;

    /** GET /invoices/ksef/{ksefNumber} @return string invoice XML exactly as KSeF stores it */
    public function invoiceXml(Secret $accessToken, string $ksefNumber): string;

    /** GET /rate-limits @return array<string,mixed> */
    public function rateLimits(Secret $accessToken): array;
}
