<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport;

use DateTimeImmutable;
use Poland\Domain\Money;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Error\Redactor;
use Poland\Ksef\Http\HttpClient;
use Poland\Ksef\Http\HttpFailure;
use Poland\Ksef\Http\HttpRequest;
use Poland\Ksef\Http\HttpResponse;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\KsefInvoiceMetadata;
use Poland\Ksef\KsefNumber;
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
 * The KSeF 2.0 API over HTTP, written against the pinned OpenAPI 2.7.1.
 *
 * Three rules hold in every method:
 *  - a credential enters a request only through a secret header and never
 *    through a message; every text that could echo one passes the Redactor;
 *  - every response is validated for the fields the contract marks required
 *    before anything is returned, and a response that does not fit is
 *    MALFORMED_RESPONSE with an unknown outcome — never an empty result;
 *  - every request carries an explicit connect timeout and a total timeout.
 */
final class RealKsefTransport implements KsefTransport
{
    private readonly string $baseUrl;

    /**
     * @param array{connect?: int, request?: int, download?: int, max_response_bytes?: int} $timeouts
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly KsefEnvironment $environment,
        string $baseUrl,
        private readonly array $timeouts = [],
    ) {
        $baseUrl = rtrim($baseUrl, '/');
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new \InvalidArgumentException('Adres KSeF musi używać HTTPS.');
        }
        $this->baseUrl = $baseUrl;
    }

    public function kind(): string
    {
        return self::KIND_REAL;
    }

    public function environment(): KsefEnvironment
    {
        return $this->environment;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    // --- endpoints ----------------------------------------------------------

    public function publicKeyCertificates(): array
    {
        $data = $this->json('publicKeyCertificates', 'GET', '/security/public-key-certificates');
        if (! is_array($data) || (! array_is_list($data) && $data !== [])) {
            throw KsefException::malformed('publicKeyCertificates', 'oczekiwano listy certyfikatów', environment: $this->environment->value);
        }
        $out = [];
        foreach ($data as $item) {
            $out[] = new PublicKeyCertificate(
                $this->str($item, 'certificate', 'publicKeyCertificates'),
                $this->str($item, 'certificateId', 'publicKeyCertificates'),
                $this->str($item, 'publicKeyId', 'publicKeyCertificates'),
                $this->date($item, 'validFrom', 'publicKeyCertificates'),
                $this->date($item, 'validTo', 'publicKeyCertificates'),
                array_values(array_filter((array) ($item['usage'] ?? []), 'is_string')),
            );
        }

        return $out;
    }

    public function authChallenge(): AuthChallenge
    {
        $data = $this->json('authChallenge', 'POST', '/auth/challenge', stateChanging: false);

        return new AuthChallenge(
            $this->str($data, 'challenge', 'authChallenge'),
            $this->date($data, 'timestamp', 'authChallenge'),
            $this->int($data, 'timestampMs', 'authChallenge'),
        );
    }

    public function authWithKsefToken(string $challenge, string $contextNip, string $encryptedTokenBase64, string $publicKeyId): AuthInitiated
    {
        $data = $this->json('authWithKsefToken', 'POST', '/auth/ksef-token', [
            'challenge' => $challenge,
            'contextIdentifier' => ['type' => 'Nip', 'value' => $contextNip],
            'encryptedToken' => $encryptedTokenBase64,
            'publicKeyId' => $publicKeyId,
        ], expectedStatus: [202, 200]);

        $token = $data['authenticationToken'] ?? null;
        if (! is_array($token)) {
            throw KsefException::malformed('authWithKsefToken', 'brak authenticationToken', environment: $this->environment->value);
        }

        return new AuthInitiated(
            $this->str($data, 'referenceNumber', 'authWithKsefToken'),
            Secret::of($this->str($token, 'token', 'authWithKsefToken')),
            isset($token['validUntil']) ? $this->date($token, 'validUntil', 'authWithKsefToken') : null,
        );
    }

    public function authStatus(string $referenceNumber, Secret $authenticationToken): AuthStatus
    {
        $data = $this->json('authStatus', 'GET', '/auth/'.rawurlencode($referenceNumber), bearer: $authenticationToken);
        $status = $data['status'] ?? null;
        if (! is_array($status)) {
            throw KsefException::malformed('authStatus', 'brak status', environment: $this->environment->value);
        }

        return new AuthStatus(
            $this->int($status, 'code', 'authStatus'),
            $this->str($status, 'description', 'authStatus'),
            $this->strings($status['details'] ?? []),
            isset($data['isTokenRedeemed']) ? (bool) $data['isTokenRedeemed'] : null,
            isset($data['refreshTokenValidUntil']) ? $this->date($data, 'refreshTokenValidUntil', 'authStatus') : null,
            isset($data['authenticationMethodInfo']['code']) ? (string) $data['authenticationMethodInfo']['code'] : (isset($data['authenticationMethod']) ? (string) $data['authenticationMethod'] : null),
        );
    }

    public function redeemTokens(Secret $authenticationToken): AccessTokens
    {
        $data = $this->json('redeemTokens', 'POST', '/auth/token/redeem', bearer: $authenticationToken);

        return new AccessTokens(
            $this->tokenInfo($data['accessToken'] ?? null, 'redeemTokens'),
            $this->tokenInfo($data['refreshToken'] ?? null, 'redeemTokens'),
        );
    }

    public function refreshAccessToken(Secret $refreshToken): TokenInfo
    {
        $data = $this->json('refreshAccessToken', 'POST', '/auth/token/refresh', bearer: $refreshToken);

        return $this->tokenInfo($data['accessToken'] ?? null, 'refreshAccessToken');
    }

    public function revokeCurrentSession(Secret $accessToken): void
    {
        $this->json('revokeCurrentSession', 'DELETE', '/auth/sessions/current', bearer: $accessToken, expectedStatus: [200, 204], allowEmpty: true);
    }

    public function openOnlineSession(Secret $accessToken, array $formCode, string $encryptedSymmetricKeyBase64, string $initializationVectorBase64, string $publicKeyId): OpenedSession
    {
        $data = $this->json('openOnlineSession', 'POST', '/sessions/online', [
            'formCode' => $formCode,
            'encryption' => [
                'encryptedSymmetricKey' => $encryptedSymmetricKeyBase64,
                'initializationVector' => $initializationVectorBase64,
                'publicKeyId' => $publicKeyId,
            ],
        ], bearer: $accessToken, expectedStatus: [201, 200], stateChanging: true);

        return new OpenedSession(
            $this->str($data, 'referenceNumber', 'openOnlineSession'),
            $this->date($data, 'validUntil', 'openOnlineSession'),
        );
    }

    public function sendInvoice(Secret $accessToken, string $sessionReference, string $invoiceHashBase64, int $invoiceSize, string $encryptedInvoiceHashBase64, int $encryptedInvoiceSize, string $encryptedInvoiceContentBase64): string
    {
        $data = $this->json('sendInvoice', 'POST', '/sessions/online/'.rawurlencode($sessionReference).'/invoices', [
            'invoiceHash' => $invoiceHashBase64,
            'invoiceSize' => $invoiceSize,
            'encryptedInvoiceHash' => $encryptedInvoiceHashBase64,
            'encryptedInvoiceSize' => $encryptedInvoiceSize,
            'encryptedInvoiceContent' => $encryptedInvoiceContentBase64,
            'offlineMode' => false,
        ], bearer: $accessToken, expectedStatus: [202, 200, 201], stateChanging: true, timeoutKey: 'upload');

        return $this->str($data, 'referenceNumber', 'sendInvoice');
    }

    public function closeOnlineSession(Secret $accessToken, string $sessionReference): void
    {
        $this->json('closeOnlineSession', 'POST', '/sessions/online/'.rawurlencode($sessionReference).'/close', bearer: $accessToken, expectedStatus: [200, 202, 204], allowEmpty: true, stateChanging: true);
    }

    public function sessionStatus(Secret $accessToken, string $sessionReference): SessionStatus
    {
        $data = $this->json('sessionStatus', 'GET', '/sessions/'.rawurlencode($sessionReference), bearer: $accessToken);
        $status = $data['status'] ?? null;
        if (! is_array($status)) {
            throw KsefException::malformed('sessionStatus', 'brak status', environment: $this->environment->value);
        }
        $pages = [];
        foreach ((array) ($data['upo']['pages'] ?? []) as $page) {
            if (is_array($page) && isset($page['referenceNumber'])) {
                $pages[] = ['referenceNumber' => (string) $page['referenceNumber'], 'downloadUrl' => (string) ($page['downloadUrl'] ?? '')];
            }
        }

        return new SessionStatus(
            $this->int($status, 'code', 'sessionStatus'),
            $this->str($status, 'description', 'sessionStatus'),
            $this->strings($status['details'] ?? []),
            $this->date($data, 'dateCreated', 'sessionStatus'),
            $this->date($data, 'dateUpdated', 'sessionStatus'),
            isset($data['validUntil']) ? $this->date($data, 'validUntil', 'sessionStatus') : null,
            isset($data['invoiceCount']) ? (int) $data['invoiceCount'] : null,
            isset($data['successfulInvoiceCount']) ? (int) $data['successfulInvoiceCount'] : null,
            isset($data['failedInvoiceCount']) ? (int) $data['failedInvoiceCount'] : null,
            $pages,
        );
    }

    public function sessionInvoices(Secret $accessToken, string $sessionReference, ?string $continuationToken = null, int $pageSize = 50): SessionInvoicesPage
    {
        $headers = $continuationToken !== null ? ['x-continuation-token' => $continuationToken] : [];
        $data = $this->json('sessionInvoices', 'GET', '/sessions/'.rawurlencode($sessionReference).'/invoices', query: ['pageSize' => $pageSize], bearer: $accessToken, headers: $headers);
        $invoices = [];
        foreach ((array) ($data['invoices'] ?? []) as $item) {
            $invoices[] = $this->sessionInvoice($item, 'sessionInvoices');
        }

        return new SessionInvoicesPage($invoices, isset($data['continuationToken']) && $data['continuationToken'] !== '' ? (string) $data['continuationToken'] : null);
    }

    public function sessionInvoiceStatus(Secret $accessToken, string $sessionReference, string $invoiceReference): SessionInvoiceStatus
    {
        $data = $this->json('sessionInvoiceStatus', 'GET', '/sessions/'.rawurlencode($sessionReference).'/invoices/'.rawurlencode($invoiceReference), bearer: $accessToken);

        return $this->sessionInvoice($data, 'sessionInvoiceStatus');
    }

    public function sessionInvoiceUpo(Secret $accessToken, string $sessionReference, string $invoiceReference): string
    {
        return $this->xml('sessionInvoiceUpo', '/sessions/'.rawurlencode($sessionReference).'/invoices/'.rawurlencode($invoiceReference).'/upo', $accessToken);
    }

    public function sessionUpo(Secret $accessToken, string $sessionReference, string $upoReference): string
    {
        return $this->xml('sessionUpo', '/sessions/'.rawurlencode($sessionReference).'/upo/'.rawurlencode($upoReference), $accessToken);
    }

    public function queryInvoiceMetadata(Secret $accessToken, InvoiceQuery $query, int $pageOffset, int $pageSize, string $sortOrder = 'Asc'): InvoiceMetadataPage
    {
        if ($pageOffset < 0 || $pageSize < 10 || $pageSize > 250) {
            throw new \InvalidArgumentException('pageOffset >= 0 and 10 <= pageSize <= 250 per the contract.');
        }
        $data = $this->json('queryInvoiceMetadata', 'POST', '/invoices/query/metadata', $query->toArray(), query: [
            'sortOrder' => $sortOrder,
            'pageOffset' => $pageOffset,
            'pageSize' => $pageSize,
        ], bearer: $accessToken);

        if (! array_key_exists('hasMore', $data) || ! array_key_exists('isTruncated', $data) || ! isset($data['invoices']) || ! is_array($data['invoices'])) {
            throw KsefException::malformed('queryInvoiceMetadata', 'brak hasMore/isTruncated/invoices', environment: $this->environment->value);
        }

        $retrievedAt = new DateTimeImmutable();
        $invoices = [];
        foreach ($data['invoices'] as $item) {
            $invoices[] = $this->metadata($item, $retrievedAt);
        }

        return new InvoiceMetadataPage(
            $invoices,
            (bool) $data['hasMore'],
            (bool) $data['isTruncated'],
            isset($data['permanentStorageHwmDate']) ? $this->date($data, 'permanentStorageHwmDate', 'queryInvoiceMetadata') : null,
            $pageOffset,
            $pageSize,
        );
    }

    public function invoiceXml(Secret $accessToken, string $ksefNumber): string
    {
        if (! KsefNumber::isValid($ksefNumber)) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'invoiceXml', 'Nieprawidłowy numer KSeF: '.KsefNumber::reject($ksefNumber), environment: $this->environment->value);
        }

        return $this->xml('invoiceXml', '/invoices/ksef/'.rawurlencode($ksefNumber), $accessToken);
    }

    public function rateLimits(Secret $accessToken): array
    {
        return $this->json('rateLimits', 'GET', '/rate-limits', bearer: $accessToken);
    }

    // --- plumbing -----------------------------------------------------------

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @param list<int> $expectedStatus
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function json(
        string $operation,
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?Secret $bearer = null,
        array $expectedStatus = [200],
        bool $allowEmpty = false,
        bool $stateChanging = false,
        string $timeoutKey = 'request',
        array $headers = [],
    ): array {
        $response = $this->send($operation, $method, $path, $body, $query, $bearer, $stateChanging, $timeoutKey, $headers + ['Accept' => 'application/json']);
        $redactor = $this->redactor($bearer);

        if (! in_array($response->status, $expectedStatus, true)) {
            throw $this->classify($operation, $response, $redactor, $stateChanging);
        }
        if (trim($response->body) === '') {
            if ($allowEmpty) {
                return [];
            }
            throw KsefException::malformed($operation, 'pusta odpowiedź przy statusie '.$response->status, environment: $this->environment->value);
        }
        try {
            $decoded = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw KsefException::malformed($operation, 'odpowiedź nie jest poprawnym JSON', $e, $this->environment->value);
        }
        if (! is_array($decoded)) {
            throw KsefException::malformed($operation, 'odpowiedź JSON nie jest obiektem', environment: $this->environment->value);
        }

        return $decoded;
    }

    private function xml(string $operation, string $path, Secret $bearer): string
    {
        $response = $this->send($operation, 'GET', $path, null, [], $bearer, false, 'download', ['Accept' => 'application/xml, application/octet-stream, */*']);
        if ($response->status !== 200) {
            throw $this->classify($operation, $response, $this->redactor($bearer), false);
        }
        $body = $response->body;
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        if (trim($body) === '' || ! str_contains(substr(ltrim($body), 0, 200), '<')) {
            throw KsefException::malformed($operation, 'oczekiwano dokumentu XML', environment: $this->environment->value);
        }

        return $body;
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @param array<string,string> $headers
     */
    private function send(string $operation, string $method, string $path, ?array $body, array $query, ?Secret $bearer, bool $stateChanging, string $timeoutKey, array $headers): HttpResponse
    {
        $url = $this->baseUrl.$path.($query !== [] ? '?'.http_build_query($query) : '');
        $plain = $headers + ['X-Error-Format' => 'problem-details'];
        $secret = [];
        if ($body !== null) {
            $plain['Content-Type'] = 'application/json';
        }
        if ($bearer !== null) {
            $secret['Authorization'] = 'Bearer '.$bearer->reveal();
        }
        $request = new HttpRequest(
            $method,
            $url,
            $plain,
            $body !== null ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $secret,
            (int) ($this->timeouts['connect'] ?? 10),
            (int) ($this->timeouts[$timeoutKey] ?? $this->timeouts['request'] ?? 30),
            (int) ($this->timeouts['max_response_bytes'] ?? 5_000_000),
        );

        try {
            return $this->http->send($request);
        } catch (HttpFailure $failure) {
            $message = $this->redactor($bearer)->scrub($failure->getMessage());
            $category = match ($failure->kind) {
                HttpFailure::TIMEOUT => KsefErrorCategory::Timeout,
                HttpFailure::TOO_LARGE => KsefErrorCategory::MalformedResponse,
                default => KsefErrorCategory::NetworkError,
            };
            // A state-changing request that may have reached the server has an
            // unknown outcome. A connect failure never reached it.
            $outcomeKnown = ! ($stateChanging && $failure->requestWasSent) && $failure->kind !== HttpFailure::TOO_LARGE;

            throw new KsefException(
                $category,
                $operation,
                sprintf('Połączenie z KSeF (%s) nie powiodło się: %s.', $this->environment->value, $message)
                .($outcomeKnown ? '' : ' Żądanie mogło dotrzeć do KSeF — wynik NIEZNANY.'),
                outcomeKnown: $outcomeKnown,
                environment: $this->environment->value,
                previous: $failure,
            );
        }
    }

    private function classify(string $operation, HttpResponse $response, Redactor $redactor, bool $stateChanging): KsefException
    {
        [$code, $description, $details] = $this->parseError($response, $redactor);
        $status = $response->status;
        $retryAfter = null;
        if ($response->header('retry-after') !== null && ctype_digit(trim((string) $response->header('retry-after')))) {
            $retryAfter = (int) trim((string) $response->header('retry-after'));
        }

        $category = match (true) {
            $status === 401 => KsefErrorCategory::AuthenticationError,
            $status === 403 => KsefErrorCategory::AuthorizationError,
            $status === 429 => KsefErrorCategory::RateLimit,
            $status === 400, $status === 404, $status === 409, $status === 410, $status === 415, $status === 422 => KsefErrorCategory::ValidationError,
            $status >= 500 => KsefErrorCategory::ServerError,
            default => KsefErrorCategory::UnknownKsefError,
        };
        if ($status === 410) {
            $category = KsefErrorCategory::CursorError;
        }

        $message = sprintf(
            'KSeF (%s) odrzucił operację %s: HTTP %d%s%s.',
            $this->environment->value,
            $operation,
            $status,
            $code !== null ? ', kod '.$code : '',
            $description !== '' ? ' — '.$description : '',
        );

        return new KsefException(
            $category,
            $operation,
            $message,
            $status,
            $code,
            $details,
            $retryAfter,
            null,
            // A 5xx on a state-changing call may have partially happened.
            outcomeKnown: ! ($stateChanging && $status >= 500),
            environment: $this->environment->value,
        );
    }

    /**
     * Reads every error shape the pinned contract documents: problem-details
     * (`errors[].code/description/details`), the deprecated
     * `exception.exceptionDetailList[]`, and the 429 `status{}` body.
     *
     * @return array{0: int|null, 1: string, 2: list<string>}
     */
    private function parseError(HttpResponse $response, Redactor $redactor): array
    {
        $decoded = json_decode($response->body, true);
        if (! is_array($decoded)) {
            $snippet = trim(mb_substr(strip_tags($response->body), 0, 200));

            return [null, $redactor->scrub($snippet), []];
        }

        $code = null;
        $description = '';
        $details = [];

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $error) {
                if (! is_array($error)) {
                    continue;
                }
                $code ??= isset($error['code']) ? (int) $error['code'] : null;
                $description = $description === '' ? (string) ($error['description'] ?? '') : $description;
                foreach ($this->strings($error['details'] ?? []) as $d) {
                    $details[] = $d;
                }
            }
            if ($description === '' && isset($decoded['detail'])) {
                $description = (string) $decoded['detail'];
            }
        } elseif (isset($decoded['exception']['exceptionDetailList']) && is_array($decoded['exception']['exceptionDetailList'])) {
            foreach ($decoded['exception']['exceptionDetailList'] as $error) {
                if (! is_array($error)) {
                    continue;
                }
                $code ??= isset($error['exceptionCode']) ? (int) $error['exceptionCode'] : null;
                $description = $description === '' ? (string) ($error['exceptionDescription'] ?? '') : $description;
                foreach ($this->strings($error['details'] ?? []) as $d) {
                    $details[] = $d;
                }
            }
        } elseif (isset($decoded['status']) && is_array($decoded['status'])) {
            $code = isset($decoded['status']['code']) ? (int) $decoded['status']['code'] : null;
            $description = (string) ($decoded['status']['description'] ?? '');
            $details = $this->strings($decoded['status']['details'] ?? []);
        } elseif (isset($decoded['title'])) {
            $description = (string) $decoded['title'];
        }

        return [$code, $redactor->scrub($description), $redactor->scrubAll($details)];
    }

    private function redactor(?Secret $bearer): Redactor
    {
        return $bearer === null ? Redactor::none() : Redactor::none()->withSecrets([$bearer]);
    }

    /** @param array<string,mixed> $item */
    private function sessionInvoice(mixed $item, string $operation): SessionInvoiceStatus
    {
        if (! is_array($item) || ! isset($item['status']) || ! is_array($item['status'])) {
            throw KsefException::malformed($operation, 'brak status faktury', environment: $this->environment->value);
        }
        $status = $item['status'];
        $extensions = [];
        foreach ((array) ($status['extensions'] ?? []) as $k => $v) {
            $extensions[(string) $k] = $v === null ? null : (string) $v;
        }
        $ksefNumber = isset($item['ksefNumber']) && $item['ksefNumber'] !== '' ? (string) $item['ksefNumber'] : null;

        return new SessionInvoiceStatus(
            (int) ($item['ordinalNumber'] ?? 0),
            isset($item['invoiceNumber']) ? (string) $item['invoiceNumber'] : null,
            $ksefNumber,
            $this->str($item, 'referenceNumber', $operation),
            $this->str($item, 'invoiceHash', $operation),
            isset($item['acquisitionDate']) ? $this->date($item, 'acquisitionDate', $operation) : null,
            $this->date($item, 'invoicingDate', $operation),
            isset($item['permanentStorageDate']) ? $this->date($item, 'permanentStorageDate', $operation) : null,
            isset($item['upoDownloadUrl']) ? (string) $item['upoDownloadUrl'] : null,
            isset($item['upoDownloadUrlExpirationDate']) ? $this->date($item, 'upoDownloadUrlExpirationDate', $operation) : null,
            isset($item['invoicingMode']) ? (string) $item['invoicingMode'] : null,
            $this->int($status, 'code', $operation),
            $this->str($status, 'description', $operation),
            $this->strings($status['details'] ?? []),
            $extensions,
        );
    }

    private function metadata(mixed $item, DateTimeImmutable $retrievedAt): KsefInvoiceMetadata
    {
        if (! is_array($item)) {
            throw KsefException::malformed('queryInvoiceMetadata', 'pozycja listy nie jest obiektem', environment: $this->environment->value);
        }
        $ksefNumber = $this->str($item, 'ksefNumber', 'queryInvoiceMetadata');
        if (! KsefNumber::isValid($ksefNumber)) {
            throw KsefException::malformed('queryInvoiceMetadata', 'numer KSeF '.$ksefNumber.' nie przechodzi walidacji ('.KsefNumber::reject($ksefNumber).')', environment: $this->environment->value);
        }
        $buyer = is_array($item['buyer'] ?? null) ? $item['buyer'] : [];
        $seller = is_array($item['seller'] ?? null) ? $item['seller'] : [];
        $buyerIdentifier = is_array($buyer['identifier'] ?? null) ? $buyer['identifier'] : [];

        return new KsefInvoiceMetadata(
            ksefNumber: $ksefNumber,
            retrievedAt: $retrievedAt,
            invoiceDate: isset($item['issueDate']) ? $this->date($item, 'issueDate', 'queryInvoiceMetadata') : null,
            permanentStorageDate: isset($item['permanentStorageDate']) ? $this->date($item, 'permanentStorageDate', 'queryInvoiceMetadata') : null,
            invoiceNumber: isset($item['invoiceNumber']) ? (string) $item['invoiceNumber'] : null,
            sellerNip: isset($seller['nip']) ? (string) $seller['nip'] : null,
            sellerName: isset($seller['name']) ? (string) $seller['name'] : null,
            buyerNip: (($buyerIdentifier['type'] ?? null) === 'Nip' && isset($buyerIdentifier['value'])) ? (string) $buyerIdentifier['value'] : null,
            buyerName: isset($buyer['name']) ? (string) $buyer['name'] : null,
            net: $this->money($item['netAmount'] ?? null),
            vat: $this->money($item['vatAmount'] ?? null),
            gross: $this->money($item['grossAmount'] ?? null),
            currency: isset($item['currency']) ? (string) $item['currency'] : null,
            status: null,
            invoiceType: isset($item['invoiceType']) ? (string) $item['invoiceType'] : null,
            raw: $item,
        );
    }

    private function money(mixed $value): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return Money::parse(number_format((float) $value, 2, '.', ''));
        }
        try {
            return Money::parse((string) $value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string,mixed>|null $data */
    private function tokenInfo(mixed $data, string $operation): TokenInfo
    {
        if (! is_array($data)) {
            throw KsefException::malformed($operation, 'brak tokena w odpowiedzi', environment: $this->environment->value);
        }

        return new TokenInfo(Secret::of($this->str($data, 'token', $operation)), $this->date($data, 'validUntil', $operation));
    }

    /** @param array<string,mixed> $data */
    private function str(array $data, string $key, string $operation): string
    {
        if (! isset($data[$key]) || ! is_scalar($data[$key]) || (string) $data[$key] === '') {
            throw KsefException::malformed($operation, 'brak wymaganego pola '.$key, environment: $this->environment->value);
        }

        return (string) $data[$key];
    }

    /** @param array<string,mixed> $data */
    private function int(array $data, string $key, string $operation): int
    {
        if (! isset($data[$key]) || ! is_numeric($data[$key])) {
            throw KsefException::malformed($operation, 'brak wymaganego pola liczbowego '.$key, environment: $this->environment->value);
        }

        return (int) $data[$key];
    }

    /** @param array<string,mixed> $data */
    private function date(array $data, string $key, string $operation): DateTimeImmutable
    {
        $raw = $this->str($data, $key, $operation);
        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception $e) {
            throw KsefException::malformed($operation, 'pole '.$key.' nie jest datą', $e, $this->environment->value);
        }
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : ((string) (json_encode($v, JSON_UNESCAPED_UNICODE) ?: '')), $value));
    }
}
