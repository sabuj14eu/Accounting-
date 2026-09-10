<?php

declare(strict_types=1);

namespace Poland\Ksef;

use DateTimeImmutable;
use DateTimeZone;
use Poland\Domain\Money;
use Poland\Ksef\Auth\TokenEncryptor;
use Poland\Ksef\Contracts\KsefClient;
use Poland\Ksef\Contracts\KsefInvoicePage;
use Poland\Ksef\Contracts\KsefSession;
use Poland\Ksef\Http\HttpResponse;
use Poland\Ksef\Http\HttpTransport;
use Poland\Ksef\Http\KsefTransportException;

/**
 * The real KSeF client, written against KSeF API 2.0 (OpenAPI 2.7.1, docs
 * commit pinned in {@see self::API_VERSION}) — and only against that.
 *
 * Flow, exactly as the specification describes it:
 *   GET  /security/public-key-certificates   → key with usage KsefTokenEncryption
 *   POST /auth/challenge                     → challenge + timestampMs
 *   POST /auth/ksef-token                    → RSA-OAEP(token|timestampMs) → referenceNumber + authenticationToken
 *   GET  /auth/{referenceNumber}             → poll until status.code 200
 *   POST /auth/token/redeem                  → accessToken (one-time)
 *   POST /invoices/query/metadata            → Subject2 (the shop as buyer), PermanentStorage dates, Asc
 *   GET  /invoices/ksef/{ksefNumber}         → the XML
 *   DELETE /auth/sessions/current            → done
 *
 * What it refuses: any scope but InvoiceRead; a window over the API's 100-day
 * limit; a KSeF number that fails its checksum; a response missing a field
 * the contract marks required (reported as MALFORMED, never read as empty);
 * following a redirect. What it never does: log, echo or serialise the token,
 * the challenge, the encrypted blob, or an Authorization header.
 */
final class HttpKsefClient implements KsefClient
{
    /** The specification this client was written against. Changing it is a review event. */
    public const API_VERSION = '2.7.1';

    public const API_DOCS_COMMIT = '93b843d';

    public const MAX_WINDOW_DAYS = 100;

    private const AUTH_POLL_ATTEMPTS = 30;

    private const AUTH_POLL_SECONDS = 1;

    private const RETRY_AFTER_CAP_SECONDS = 60;

    /** @var callable(): DateTimeImmutable */
    private $clock;

    /** @var callable(int): void */
    private $sleep;

    /**
     * @param callable(): DateTimeImmutable|null $clock
     * @param callable(int): void|null $sleep
     */
    public function __construct(
        private readonly HttpTransport $http,
        private readonly KsefEndpoint $endpoint,
        private readonly string $ksefToken,
        private readonly int $pageSize = 100,
        private readonly int $maxRetries = 3,
        ?callable $clock = null,
        ?callable $sleep = null,
    ) {
        if ($pageSize < 10 || $pageSize > 250) {
            throw KsefTransportException::config('Rozmiar strony KSeF musi mieścić się w 10..250 (specyfikacja).');
        }
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->sleep = $sleep ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    public function scope(): KsefScope
    {
        return KsefScope::InvoiceRead;
    }

    public function isConfigured(): bool
    {
        return $this->ksefToken !== '';
    }

    public function endpoint(): KsefEndpoint
    {
        return $this->endpoint;
    }

    /**
     * Step 3 of the go-live checklist: reach the environment over TLS and read
     * the published keys — no credential is sent. Returns the key the client
     * would use to encrypt the token, so the operator can see it rotate.
     *
     * @return array{publicKeyId: string, validTo: ?string}
     */
    public function probeCertificates(): array
    {
        $certificate = $this->tokenEncryptionCertificate();

        return ['publicKeyId' => $certificate['publicKeyId'], 'validTo' => $certificate['validTo']];
    }

    // ------------------------------------------------------------------ auth

    public function openSession(): KsefSession
    {
        if (! $this->isConfigured()) {
            throw KsefTransportException::config('Brak tokenu KSeF — nie można otworzyć sesji.');
        }
        $this->scope()->assertAllowed();

        $certificate = $this->tokenEncryptionCertificate();

        $challengeResponse = $this->request('POST', '/auth/challenge', [], null, 'auth/challenge', retry: false);
        $challenge = $this->requireString($challengeResponse, 'challenge', 'auth/challenge');
        $timestampMs = $this->requireInt($challengeResponse, 'timestampMs', 'auth/challenge');

        $encrypted = TokenEncryptor::encrypt($this->ksefToken, $timestampMs, $certificate['certificate']);

        $init = $this->request('POST', '/auth/ksef-token', [], [
            'challenge' => $challenge,
            'contextIdentifier' => ['type' => 'Nip', 'value' => $this->endpoint->nip],
            'encryptedToken' => $encrypted,
            'publicKeyId' => $certificate['publicKeyId'],
        ], 'auth/ksef-token', retry: false);

        $reference = $this->requireString($init, 'referenceNumber', 'auth/ksef-token');
        $authToken = $this->requireString($init['authenticationToken'] ?? [], 'token', 'auth/ksef-token.authenticationToken');

        $this->waitForAuthentication($reference, $authToken);

        $redeemed = $this->request('POST', '/auth/token/redeem', $this->bearer($authToken), null, 'auth/token/redeem', retry: false);
        $access = $redeemed['accessToken'] ?? [];
        $accessToken = $this->requireString($access, 'token', 'auth/token/redeem.accessToken');
        $validUntil = $this->optionalDateTime($access['validUntil'] ?? null);

        return new KsefSession($accessToken, $reference, ($this->clock)(), $validUntil);
    }

    private function waitForAuthentication(string $reference, string $authToken): void
    {
        for ($attempt = 1; $attempt <= self::AUTH_POLL_ATTEMPTS; $attempt++) {
            $status = $this->request('GET', '/auth/'.rawurlencode($reference), $this->bearer($authToken), null, 'auth/status', retry: true);
            $code = $this->requireInt($status['status'] ?? [], 'code', 'auth/status.status');
            $description = (string) ($status['status']['description'] ?? '');
            $details = array_map('strval', (array) ($status['status']['details'] ?? []));

            if ($code === 200) {
                return;
            }
            if ($code === 100) {
                ($this->sleep)(self::AUTH_POLL_SECONDS);

                continue;
            }

            throw KsefTransportException::authFailed(sprintf(
                'status %d — %s%s. Token mógł zostać unieważniony, wygasnąć lub nie mieć uprawnienia InvoiceRead.',
                $code,
                $description,
                $details !== [] ? ' ('.implode('; ', $details).')' : '',
            ), [$code]);
        }

        throw KsefTransportException::authFailed(sprintf(
            'KSeF nie zakończył uwierzytelniania w %d s.',
            self::AUTH_POLL_ATTEMPTS * self::AUTH_POLL_SECONDS,
        ));
    }

    /** @return array{certificate: string, publicKeyId: string, validTo: ?string} */
    private function tokenEncryptionCertificate(): array
    {
        $response = $this->send('GET', '/security/public-key-certificates', $this->jsonHeaders(), null, 'security/public-key-certificates', retry: true);
        $list = json_decode($response->body, true);
        if (! is_array($list) || array_is_list($list) === false) {
            throw KsefTransportException::malformed('lista certyfikatów KSeF nie jest tablicą');
        }

        $now = ($this->clock)();
        $best = null;
        foreach ($list as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $usage = array_map('strval', (array) ($entry['usage'] ?? []));
            if (! in_array('KsefTokenEncryption', $usage, true)) {
                continue;
            }
            $from = $this->optionalDateTime($entry['validFrom'] ?? null);
            $to = $this->optionalDateTime($entry['validTo'] ?? null);
            if ($from !== null && $from > $now) {
                continue;
            }
            if ($to !== null && $to < $now) {
                continue;
            }
            if (! isset($entry['certificate'], $entry['publicKeyId'])) {
                continue;
            }
            if ($best === null || ($from !== null && $best['from'] !== null && $from > $best['from'])) {
                $best = [
                    'certificate' => (string) $entry['certificate'],
                    'publicKeyId' => (string) $entry['publicKeyId'],
                    'from' => $from,
                    'validTo' => $to?->format(DATE_ATOM),
                ];
            }
        }

        if ($best === null) {
            throw KsefTransportException::malformed(
                'KSeF nie opublikował ważnego certyfikatu o przeznaczeniu KsefTokenEncryption — nie można zaszyfrować tokenu.',
            );
        }

        return ['certificate' => $best['certificate'], 'publicKeyId' => $best['publicKeyId'], 'validTo' => $best['validTo']];
    }

    // ----------------------------------------------------------------- query

    public function queryInvoices(
        KsefSession $session,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $cursor = null,
    ): KsefInvoicePage {
        $this->assertSessionUsable($session);

        $state = $this->decodeCursor($cursor);
        $windowFrom = $state['from'] ?? $from;
        $pageOffset = $state['offset'];

        if ($to < $windowFrom) {
            throw KsefTransportException::config('Koniec okna zapytania KSeF jest wcześniejszy niż jego początek.');
        }
        $days = (int) $windowFrom->diff($to)->format('%a');
        if ($days > self::MAX_WINDOW_DAYS) {
            throw KsefTransportException::config(sprintf(
                'Okno zapytania KSeF obejmuje %d dni; specyfikacja dopuszcza najwyżej %d. Podziel zakres.',
                $days,
                self::MAX_WINDOW_DAYS,
            ));
        }

        $path = sprintf(
            '/invoices/query/metadata?pageOffset=%d&pageSize=%d&sortOrder=Asc',
            $pageOffset,
            $this->pageSize,
        );
        $body = [
            'subjectType' => 'Subject2',
            'dateRange' => [
                'dateType' => 'PermanentStorage',
                'from' => $this->iso($windowFrom),
                'to' => $this->iso($to),
            ],
        ];

        $data = $this->request('POST', $path, $this->bearer($session->reveal()), $body, 'invoices/query/metadata', retry: true);

        $hasMore = $this->requireBool($data, 'hasMore', 'invoices/query/metadata');
        $isTruncated = $this->requireBool($data, 'isTruncated', 'invoices/query/metadata');
        $rows = $data['invoices'] ?? null;
        if (! is_array($rows)) {
            throw KsefTransportException::malformed('odpowiedź invoices/query/metadata nie zawiera tablicy "invoices"');
        }

        $retrievedAt = ($this->clock)();
        $invoices = [];
        $lastStorage = null;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw KsefTransportException::malformed(sprintf('pozycja %d listy faktur nie jest obiektem', $index));
            }
            $metadata = $this->mapMetadata($row, $retrievedAt);
            $invoices[] = $metadata;
            if ($metadata->permanentStorageDate !== null) {
                $lastStorage = $metadata->permanentStorageDate;
            }
        }

        $next = null;
        if ($hasMore) {
            if ($isTruncated) {
                // Spec: narrow dateRange.from to the last returned record and
                // restart paging. Same second is re-requested; the unique index
                // rejects the repeats, and nothing after them is skipped.
                if ($lastStorage === null) {
                    throw KsefTransportException::malformed('KSeF zgłosił isTruncated bez daty ostatniego rekordu');
                }
                $next = $this->encodeCursor(0, $lastStorage);
            } else {
                $next = $this->encodeCursor($pageOffset + 1, $state['from']);
            }
        }

        return new KsefInvoicePage($invoices, $next);
    }

    public function fetchInvoiceXml(KsefSession $session, string $ksefNumber): string
    {
        $this->assertSessionUsable($session);
        KsefNumber::assertValid($ksefNumber);

        $headers = $this->bearer($session->reveal());
        $headers['Accept'] = 'application/xml';

        $response = $this->send('GET', '/invoices/ksef/'.rawurlencode($ksefNumber), $headers, null, 'invoices/ksef', retry: true);
        $xml = $response->body;
        if (! str_contains(ltrim(substr($xml, 0, 512)), '<')) {
            throw KsefTransportException::malformed('treść faktury pobrana z KSeF nie wygląda na XML');
        }

        return $xml;
    }

    public function closeSession(KsefSession $session): void
    {
        try {
            $this->send('DELETE', '/auth/sessions/current', $this->bearer($session->reveal()), null, 'auth/sessions/current', retry: false);
        } catch (KsefTransportException) {
            // Best-effort: the access token expires by itself. The import is
            // already durable; a failed logout must not fail the run.
        }
    }

    // ------------------------------------------------------------- plumbing

    private function assertSessionUsable(KsefSession $session): void
    {
        if ($session->isExpired(($this->clock)())) {
            throw new KsefTransportException(
                KsefTransportException::UNAUTHORISED,
                'Sesja KSeF wygasła — otwórz nową sesję.',
            );
        }
    }

    /**
     * Decode a JSON body and enforce 2xx. Retries are the caller's decision:
     * reads and the metadata query are safe to repeat, auth initiation is not.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $json
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $headers, ?array $json, string $operation, bool $retry): array
    {
        $headers = $headers + $this->jsonHeaders();
        $body = null;
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return $this->send($method, $path, $headers, $body, $operation, $retry)->json();
    }

    /** @param array<string,string> $headers */
    private function send(string $method, string $path, array $headers, ?string $body, string $operation, bool $retry): HttpResponse
    {
        $headers = $headers + $this->jsonHeaders();
        $url = $this->endpoint->url($path);
        $attempts = $retry ? $this->maxRetries + 1 : 1;
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http->send($method, $url, $headers, $body);
            } catch (KsefTransportException $e) {
                if ($e->kind !== KsefTransportException::NETWORK || $attempt === $attempts) {
                    throw $e;
                }
                ($this->sleep)($this->backoffSeconds($attempt));

                continue;
            }

            if ($response->isSuccess()) {
                return $response;
            }

            $last = KsefTransportException::fromResponse($response, $operation);

            $retryable = $response->status === 429 || $response->status >= 500;
            if (! $retryable || $attempt === $attempts) {
                throw $last;
            }

            $wait = $response->status === 429
                ? min($response->retryAfterSeconds() ?? $this->backoffSeconds($attempt), self::RETRY_AFTER_CAP_SECONDS)
                : $this->backoffSeconds($attempt);
            ($this->sleep)($wait);
        }

        throw $last ?? KsefTransportException::network('nieoczekiwany koniec prób');
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(2 ** $attempt, 30);
    }

    /** @return array<string,string> */
    private function jsonHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Error-Format' => 'problem-details',
            'User-Agent' => 'signalmesh-poland-accounting/ksef-'.self::API_VERSION,
        ];
    }

    /** @return array<string,string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function iso(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** @return array{offset:int, from: ?DateTimeImmutable} */
    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return ['offset' => 0, 'from' => null];
        }
        $decoded = json_decode($cursor, true);
        if (! is_array($decoded) || ! isset($decoded['o']) || ! is_int($decoded['o']) || $decoded['o'] < 0) {
            throw KsefTransportException::config('Kursor synchronizacji KSeF jest uszkodzony — wyczyść go i uruchom ponownie.');
        }
        $from = null;
        if (isset($decoded['f']) && is_string($decoded['f'])) {
            $from = $this->optionalDateTime($decoded['f']);
            if ($from === null) {
                throw KsefTransportException::config('Kursor synchronizacji KSeF ma niepoprawną datę.');
            }
        }

        return ['offset' => $decoded['o'], 'from' => $from];
    }

    private function encodeCursor(int $offset, ?DateTimeImmutable $from): string
    {
        $payload = ['o' => $offset];
        if ($from !== null) {
            $payload['f'] = $this->iso($from);
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $row */
    private function mapMetadata(array $row, DateTimeImmutable $retrievedAt): KsefInvoiceMetadata
    {
        $number = $this->requireString($row, 'ksefNumber', 'InvoiceMetadata');
        if (! KsefNumber::isValid($number)) {
            throw KsefTransportException::malformed('metadane zawierają numer KSeF o błędnym formacie lub sumie kontrolnej');
        }

        $buyer = is_array($row['buyer'] ?? null) ? $row['buyer'] : [];
        $buyerId = is_array($buyer['identifier'] ?? null) ? $buyer['identifier'] : [];
        $seller = is_array($row['seller'] ?? null) ? $row['seller'] : [];

        return new KsefInvoiceMetadata(
            ksefNumber: $number,
            retrievedAt: $retrievedAt,
            invoiceDate: $this->optionalDateTime($row['issueDate'] ?? null),
            permanentStorageDate: $this->optionalDateTime($row['permanentStorageDate'] ?? null),
            invoiceNumber: isset($row['invoiceNumber']) ? (string) $row['invoiceNumber'] : null,
            sellerNip: isset($seller['nip']) ? (string) $seller['nip'] : null,
            sellerName: isset($seller['name']) ? (string) $seller['name'] : null,
            buyerNip: (($buyerId['type'] ?? null) === 'Nip' && isset($buyerId['value'])) ? (string) $buyerId['value'] : null,
            buyerName: isset($buyer['name']) ? (string) $buyer['name'] : null,
            net: $this->optionalMoney($row['netAmount'] ?? null),
            vat: $this->optionalMoney($row['vatAmount'] ?? null),
            gross: $this->optionalMoney($row['grossAmount'] ?? null),
            currency: isset($row['currency']) ? (string) $row['currency'] : null,
            status: isset($row['invoicingMode']) ? (string) $row['invoicingMode'] : null,
            invoiceType: isset($row['invoiceType']) ? (string) $row['invoiceType'] : null,
            raw: $row,
        );
    }

    private function optionalMoney(mixed $value): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw KsefTransportException::malformed('kwota w metadanych KSeF nie jest liczbą');
        }

        return Money::parse(number_format((float) $value, 2, '.', ''));
    }

    private function optionalDateTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<string,mixed> $data */
    private function requireString(array $data, string $key, string $where): string
    {
        if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') {
            throw KsefTransportException::malformed(sprintf('brak wymaganego pola "%s" w odpowiedzi %s', $key, $where));
        }

        return $data[$key];
    }

    /** @param array<string,mixed> $data */
    private function requireInt(array $data, string $key, string $where): int
    {
        if (! isset($data[$key]) || ! is_int($data[$key])) {
            throw KsefTransportException::malformed(sprintf('brak wymaganego pola liczbowego "%s" w odpowiedzi %s', $key, $where));
        }

        return $data[$key];
    }

    /** @param array<string,mixed> $data */
    private function requireBool(array $data, string $key, string $where): bool
    {
        if (! array_key_exists($key, $data) || ! is_bool($data[$key])) {
            throw KsefTransportException::malformed(sprintf('brak wymaganego pola logicznego "%s" w odpowiedzi %s', $key, $where));
        }

        return $data[$key];
    }

    public function __toString(): string
    {
        return 'HttpKsefClient('.$this->endpoint->environment->value.', '.$this->endpoint->host().', token=[REDACTED])';
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'environment' => $this->endpoint->environment->value,
            'host' => $this->endpoint->host(),
            'nip' => $this->endpoint->nip,
            'ksefToken' => '[REDACTED]',
            'api_version' => self::API_VERSION,
        ];
    }
}
