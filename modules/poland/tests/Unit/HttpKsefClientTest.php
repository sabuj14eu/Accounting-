<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Contracts\KsefSession;
use Poland\Ksef\Http\KsefTransportException;
use Poland\Ksef\HttpKsefClient;
use Poland\Ksef\KsefEndpoint;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\KsefNumber;
use Poland\Tests\Support\RecordedTransport;

/**
 * The real client against recorded KSeF 2.0 responses (shapes from
 * open-api.json 2.7.1). Test names carry the go-live checklist step they are
 * the unit-level evidence for; the live half of each step is recorded by
 * `php artisan poland:ksef-check`.
 */
final class HttpKsefClientTest extends TestCase
{
    private const TOKEN = 'ksef-secret-token-AAAA1111';

    private const NIP = '5265877635';

    private string $certDer = '';

    private string $privateKeyPem = '';

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKeyPem);
        $csr = openssl_csr_new(['commonName' => 'ksef'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);
        $this->certDer = base64_encode((string) base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem) ?? '', true));
        $this->sleeps = [];
    }

    private function endpoint(): KsefEndpoint
    {
        return new KsefEndpoint(KsefEnvironment::Test, 'https://ksef-test.invalid/v2', self::NIP);
    }

    private function client(RecordedTransport $http, int $pageSize = 10, int $maxRetries = 2): HttpKsefClient
    {
        return new HttpKsefClient(
            $http,
            $this->endpoint(),
            self::TOKEN,
            $pageSize,
            $maxRetries,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-10T12:00:00Z'),
            function (int $s): void {
                $this->sleeps[] = $s;
            },
        );
    }

    private function certificates(): array
    {
        return [
            ['certificate' => $this->certDer, 'certificateId' => 'old', 'publicKeyId' => 'PK-EXPIRED', 'validFrom' => '2024-01-01T00:00:00Z', 'validTo' => '2025-01-01T00:00:00Z', 'usage' => ['KsefTokenEncryption']],
            ['certificate' => $this->certDer, 'certificateId' => 'sym', 'publicKeyId' => 'PK-SYMMETRIC', 'validFrom' => '2026-01-01T00:00:00Z', 'validTo' => '2027-01-01T00:00:00Z', 'usage' => ['SymmetricKeyEncryption']],
            ['certificate' => $this->certDer, 'certificateId' => 'cur', 'publicKeyId' => 'PK-CURRENT', 'validFrom' => '2026-01-01T00:00:00Z', 'validTo' => '2027-01-01T00:00:00Z', 'usage' => ['KsefTokenEncryption']],
        ];
    }

    private function scriptHappyAuth(RecordedTransport $http): void
    {
        $http->on('GET', '/v2/security/public-key-certificates', RecordedTransport::json(200, $this->certificates()));
        $http->on('POST', '/v2/auth/challenge', RecordedTransport::json(200, [
            'challenge' => '20260910-CR-1234567890ABCDEF12345678-XY',
            'timestamp' => '2026-09-10T12:00:00Z',
            'timestampMs' => 1789041600000,
            'clientIp' => '203.0.113.7',
        ]));
        $http->on('POST', '/v2/auth/ksef-token', RecordedTransport::json(202, [
            'referenceNumber' => '20260910-AU-ABCDEFABCDEFABCDEFABCDEF-AB',
            'authenticationToken' => ['token' => 'AUTH-JWT-eyJ', 'validUntil' => '2026-09-10T12:10:00Z'],
        ]));
        $http->on('GET', '/v2/auth/20260910-AU-', RecordedTransport::json(200, [
            'startDate' => '2026-09-10T12:00:00Z',
            'authenticationMethod' => 'Token',
            'authenticationMethodInfo' => ['category' => 'Token', 'code' => 'Token', 'displayName' => 'Token KSeF'],
            'status' => ['code' => 100, 'description' => 'Uwierzytelnianie w toku'],
        ]), RecordedTransport::json(200, [
            'startDate' => '2026-09-10T12:00:00Z',
            'authenticationMethod' => 'Token',
            'authenticationMethodInfo' => ['category' => 'Token', 'code' => 'Token', 'displayName' => 'Token KSeF'],
            'status' => ['code' => 200, 'description' => 'Uwierzytelnianie zakończone sukcesem'],
        ]));
        $http->on('POST', '/v2/auth/token/redeem', RecordedTransport::json(200, [
            'accessToken' => ['token' => 'ACCESS-JWT-eyJ', 'validUntil' => '2026-09-10T13:00:00Z'],
            'refreshToken' => ['token' => 'REFRESH-JWT-eyJ', 'validUntil' => '2026-09-17T12:00:00Z'],
        ]));
        $http->on('DELETE', '/v2/auth/sessions/current', RecordedTransport::raw(204, ''));
    }

    private function metadataRow(string $number, string $stored, float $gross = 123.00): array
    {
        return [
            'ksefNumber' => $number,
            'invoiceNumber' => 'FV/1/2026',
            'issueDate' => '2026-09-01',
            'invoicingDate' => $stored,
            'acquisitionDate' => $stored,
            'permanentStorageDate' => $stored,
            'seller' => ['nip' => '1234563218', 'name' => 'Hurtownia Sp. z o.o.'],
            'buyer' => ['identifier' => ['type' => 'Nip', 'value' => self::NIP], 'name' => 'Kebab'],
            'netAmount' => 100.0,
            'grossAmount' => $gross,
            'vatAmount' => 23.0,
            'currency' => 'PLN',
            'invoicingMode' => 'Online',
            'invoiceType' => 'Vat',
            'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
            'isSelfInvoicing' => false,
            'hasAttachment' => false,
            'invoiceHash' => base64_encode(hash('sha256', $number, true)),
        ];
    }

    private function number(string $seed, string $date = '2026-09-02'): string
    {
        return KsefNumber::synthetic('1234563218', new DateTimeImmutable($date), $seed);
    }

    // ---- step 4: authentication ------------------------------------------

    public function test_step_4_authenticates_with_the_token_flow_exactly_as_specified(): void
    {
        $http = new RecordedTransport();
        $this->scriptHappyAuth($http);

        $session = $this->client($http)->openSession();

        self::assertSame('20260910-AU-ABCDEFABCDEFABCDEFABCDEF-AB', $session->reference);
        self::assertSame('ACCESS-JWT-eyJ', $session->reveal());
        self::assertSame('2026-09-10T13:00:00+00:00', $session->expiresAt?->format(DATE_ATOM));
        self::assertSame([
            'GET /v2/security/public-key-certificates',
            'POST /v2/auth/challenge',
            'POST /v2/auth/ksef-token',
            'GET /v2/auth/20260910-AU-ABCDEFABCDEFABCDEFABCDEF-AB',
            'GET /v2/auth/20260910-AU-ABCDEFABCDEFABCDEFABCDEF-AB',
            'POST /v2/auth/token/redeem',
        ], $http->paths());
        self::assertSame([1], $this->sleeps, 'polled once while status was 100');

        $init = json_decode((string) $http->requests[2]['body'], true);
        self::assertSame('20260910-CR-1234567890ABCDEF12345678-XY', $init['challenge']);
        self::assertSame(['type' => 'Nip', 'value' => self::NIP], $init['contextIdentifier']);
        self::assertSame('PK-CURRENT', $init['publicKeyId'], 'picks the valid KsefTokenEncryption key, not the expired or symmetric one');
        self::assertArrayNotHasKey('Authorization', $http->requests[2]['headers']);
        self::assertSame('problem-details', $http->requests[2]['headers']['X-Error-Format']);

        // The encrypted blob really is token|timestampMs under the published key.
        $decrypted = $this->decryptOaep((string) base64_decode($init['encryptedToken'], true));
        self::assertSame(self::TOKEN.'|1789041600000', $decrypted);

        // Status and redeem carry the AUTHENTICATION token, not the KSeF token.
        self::assertSame('Bearer AUTH-JWT-eyJ', $http->requests[3]['headers']['Authorization']);
        self::assertSame('Bearer AUTH-JWT-eyJ', $http->requests[5]['headers']['Authorization']);
    }

    public function test_step_4_the_raw_token_never_travels_and_never_appears_in_output(): void
    {
        $http = new RecordedTransport();
        $this->scriptHappyAuth($http);
        $client = $this->client($http);
        $client->openSession();

        foreach ($http->requests as $request) {
            self::assertStringNotContainsString(self::TOKEN, (string) $request['body']);
            self::assertStringNotContainsString(self::TOKEN, json_encode($request['headers']));
            self::assertStringNotContainsString(self::TOKEN, $request['url']);
        }
        self::assertStringNotContainsString(self::TOKEN, (string) $client);
        self::assertStringNotContainsString(self::TOKEN, print_r($client, true));
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($client));
    }

    public function test_step_4_a_missing_encryption_certificate_is_reported_not_worked_around(): void
    {
        $http = new RecordedTransport();
        $http->on('GET', '/v2/security/public-key-certificates', RecordedTransport::json(200, [
            ['certificate' => $this->certDer, 'certificateId' => 'x', 'publicKeyId' => 'PK', 'validFrom' => '2026-01-01T00:00:00Z', 'validTo' => '2027-01-01T00:00:00Z', 'usage' => ['SymmetricKeyEncryption']],
        ]));

        try {
            $this->client($http)->openSession();
            self::fail('expected refusal');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::MALFORMED, $e->kind);
            self::assertStringContainsString('KsefTokenEncryption', $e->getMessage());
        }
        self::assertCount(1, $http->requests, 'nothing was sent after the certificate check failed');
    }

    // ---- step 5: InvoiceRead authorisation --------------------------------

    public function test_step_5_the_client_holds_invoice_read_and_nothing_else(): void
    {
        $client = $this->client(new RecordedTransport());
        self::assertSame('InvoiceRead', $client->scope()->value);
        self::assertTrue($client->scope()->isAllowed());
    }

    public function test_step_5_a_403_on_query_is_an_authorisation_problem_never_an_empty_inbox(): void
    {
        $http = new RecordedTransport();
        $http->on('POST', '/v2/invoices/query/metadata', RecordedTransport::json(403, [
            'title' => 'Forbidden', 'status' => 403, 'detail' => 'Brak uprawnienia InvoiceRead.', 'reasonCode' => 'PermissionDenied', 'timestamp' => '2026-09-10T12:00:00Z',
        ]));

        try {
            $this->client($http)->queryInvoices($this->session(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-10'));
            self::fail('expected exception');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::UNAUTHORISED, $e->kind);
            self::assertTrue($e->isCredentialProblem());
            self::assertSame(403, $e->httpStatus);
            self::assertStringContainsString('InvoiceRead', $e->getMessage());
        }
    }

    // ---- step 6: retrieval --------------------------------------------------

    public function test_step_6_queries_as_buyer_by_permanent_storage_date_and_maps_metadata(): void
    {
        $http = new RecordedTransport();
        $n1 = $this->number('a');
        $http->on('POST', '/v2/invoices/query/metadata', RecordedTransport::json(200, [
            'hasMore' => false, 'isTruncated' => false, 'permanentStorageHwmDate' => '2026-09-10T11:50:00Z',
            'invoices' => [$this->metadataRow($n1, '2026-09-02T08:00:00Z')],
        ]));

        $page = $this->client($http)->queryInvoices(
            $this->session(),
            new DateTimeImmutable('2026-09-01T00:00:00+02:00'),
            new DateTimeImmutable('2026-09-10T12:00:00Z'),
        );

        self::assertSame('POST /v2/invoices/query/metadata?pageOffset=0&pageSize=10&sortOrder=Asc', $http->paths()[0]);
        $body = json_decode((string) $http->requests[0]['body'], true);
        self::assertSame('Subject2', $body['subjectType']);
        self::assertSame('PermanentStorage', $body['dateRange']['dateType']);
        self::assertSame('2026-08-31T22:00:00Z', $body['dateRange']['from'], 'sent in UTC');
        self::assertSame('2026-09-10T12:00:00Z', $body['dateRange']['to']);
        self::assertSame('Bearer ACCESS-JWT-eyJ', $http->requests[0]['headers']['Authorization']);

        self::assertFalse($page->hasMore());
        self::assertCount(1, $page->invoices);
        $m = $page->invoices[0];
        self::assertSame($n1, $m->ksefNumber);
        self::assertSame('FV/1/2026', $m->invoiceNumber);
        self::assertSame('2026-09-01', $m->invoiceDate?->format('Y-m-d'));
        self::assertSame('2026-09-02T08:00:00+00:00', $m->permanentStorageDate?->format(DATE_ATOM));
        self::assertSame('1234563218', $m->sellerNip);
        self::assertSame(self::NIP, $m->buyerNip);
        self::assertTrue($m->isIncomingFor(self::NIP));
        self::assertSame('123.00', $m->gross?->jsonSerialize());
        self::assertSame('23.00', $m->vat?->jsonSerialize());
        self::assertSame('Vat', $m->invoiceType);
        self::assertFalse($m->isCorrection());
        self::assertSame('Online', $m->status);
        self::assertSame('2026-09-10T12:00:00+00:00', $m->retrievedAt->format(DATE_ATOM));
        self::assertSame([], $m->missingFields());
    }

    public function test_step_6_fetches_the_xml_by_number_and_checks_it_is_xml(): void
    {
        $http = new RecordedTransport();
        $n = $this->number('x');
        $http->on('GET', '/v2/invoices/ksef/'.$n, RecordedTransport::raw(200, '<?xml version="1.0"?><Faktura/>', ['Content-Type' => 'application/xml']));

        $xml = $this->client($http)->fetchInvoiceXml($this->session(), $n);

        self::assertSame('<?xml version="1.0"?><Faktura/>', $xml);
        self::assertSame('application/xml', $http->requests[0]['headers']['Accept']);
    }

    public function test_step_6_a_malformed_number_is_never_put_into_a_url(): void
    {
        $http = new RecordedTransport();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client($http)->fetchInvoiceXml($this->session(), '5265877635-20250626-010080DD2B5E-27');
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    // ---- step 7: pagination / cursors -------------------------------------

    public function test_step_7_has_more_advances_the_page_offset_and_stops_on_the_last_page(): void
    {
        $http = new RecordedTransport();
        $n1 = $this->number('p1');
        $n2 = $this->number('p2');
        $n3 = $this->number('p3');
        $http->on('POST', '/v2/invoices/query/metadata',
            RecordedTransport::json(200, ['hasMore' => true, 'isTruncated' => false, 'invoices' => [$this->metadataRow($n1, '2026-09-02T08:00:00Z'), $this->metadataRow($n2, '2026-09-03T08:00:00Z')]]),
            RecordedTransport::json(200, ['hasMore' => false, 'isTruncated' => false, 'invoices' => [$this->metadataRow($n3, '2026-09-04T08:00:00Z')]]),
        );
        $client = $this->client($http);
        $from = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-09-10T00:00:00Z');

        $first = $client->queryInvoices($this->session(), $from, $to);
        self::assertTrue($first->hasMore());
        $second = $client->queryInvoices($this->session(), $from, $to, $first->nextCursor);
        self::assertFalse($second->hasMore());

        self::assertStringContainsString('pageOffset=0', $http->paths()[0]);
        self::assertStringContainsString('pageOffset=1', $http->paths()[1]);
        self::assertSame([$n1, $n2, $n3], array_map(static fn ($m) => $m->ksefNumber, [...$first->invoices, ...$second->invoices]));
        // The window itself did not change between pages.
        self::assertSame(
            json_decode((string) $http->requests[0]['body'], true)['dateRange'],
            json_decode((string) $http->requests[1]['body'], true)['dateRange'],
        );
    }

    public function test_step_7_is_truncated_narrows_from_to_the_last_record_and_resets_the_offset(): void
    {
        $http = new RecordedTransport();
        $n1 = $this->number('t1');
        $n2 = $this->number('t2');
        $http->on('POST', '/v2/invoices/query/metadata',
            RecordedTransport::json(200, ['hasMore' => true, 'isTruncated' => true, 'invoices' => [$this->metadataRow($n1, '2026-09-05T10:20:30Z')]]),
            RecordedTransport::json(200, ['hasMore' => false, 'isTruncated' => false, 'invoices' => [$this->metadataRow($n2, '2026-09-06T00:00:00Z')]]),
        );
        $client = $this->client($http);
        $from = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-09-10T00:00:00Z');

        $first = $client->queryInvoices($this->session(), $from, $to);
        $client->queryInvoices($this->session(), $from, $to, $first->nextCursor);

        self::assertStringContainsString('pageOffset=0', $http->paths()[1]);
        self::assertSame('2026-09-05T10:20:30Z', json_decode((string) $http->requests[1]['body'], true)['dateRange']['from']);
    }

    public function test_step_7_a_corrupt_cursor_is_refused_rather_than_read_as_page_zero(): void
    {
        $http = new RecordedTransport();
        $this->expectExceptionMessageMatches('/Kursor .* uszkodzony/u');
        try {
            $this->client($http)->queryInvoices($this->session(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-10'), 'garbage');
        } finally {
            self::assertSame([], $http->requests, 'a corrupt cursor never reaches the API as page 0');
        }
    }

    public function test_step_7_a_window_over_the_api_limit_is_refused_before_sending(): void
    {
        $http = new RecordedTransport();
        $this->expectExceptionMessageMatches('/100/');
        try {
            $this->client($http)->queryInvoices($this->session(), new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-09-10'));
        } finally {
            self::assertSame([], $http->requests);
        }
    }

    // ---- step 8: retries / timeouts ---------------------------------------

    public function test_step_8_429_honours_retry_after_and_then_succeeds(): void
    {
        $http = new RecordedTransport();
        $http->on('POST', '/v2/invoices/query/metadata',
            RecordedTransport::json(429, ['title' => 'Too Many Requests', 'status' => 429, 'detail' => 'limit', 'instance' => '/x', 'timestamp' => 't', 'traceId' => 'tr-1'], ['Retry-After' => '3']),
            RecordedTransport::json(200, ['hasMore' => false, 'isTruncated' => false, 'invoices' => []]),
        );

        $page = $this->client($http)->queryInvoices($this->session(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-10'));

        self::assertCount(0, $page->invoices);
        self::assertSame([3], $this->sleeps);
        self::assertCount(2, $http->requests);
    }

    public function test_step_8_5xx_is_retried_with_backoff_then_reported(): void
    {
        $http = new RecordedTransport();
        $http->on('POST', '/v2/invoices/query/metadata',
            RecordedTransport::raw(503, 'unavailable'),
            RecordedTransport::raw(502, 'bad gateway'),
            RecordedTransport::raw(500, 'boom'),
        );

        try {
            $this->client($http, maxRetries: 2)->queryInvoices($this->session(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-10'));
            self::fail('expected exception');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::SERVER, $e->kind);
            self::assertSame(500, $e->httpStatus);
        }
        self::assertCount(3, $http->requests, '1 try + 2 retries');
        self::assertSame([2, 4], $this->sleeps);
    }

    public function test_step_8_a_timeout_is_retried_and_finally_named_as_a_network_failure(): void
    {
        $http = new RecordedTransport();
        $http->on('GET', '/v2/invoices/ksef/',
            KsefTransportException::network('Operation timed out after 60000 milliseconds (curl 28)'),
            KsefTransportException::network('Operation timed out after 60000 milliseconds (curl 28)'),
            KsefTransportException::network('Operation timed out after 60000 milliseconds (curl 28)'),
        );

        try {
            $this->client($http, maxRetries: 2)->fetchInvoiceXml($this->session(), $this->number('z'));
            self::fail('expected exception');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::NETWORK, $e->kind);
            self::assertStringContainsString('timed out', $e->getMessage());
        }
        self::assertCount(3, $http->requests);
    }

    public function test_step_8_authentication_initiation_is_never_retried(): void
    {
        $http = new RecordedTransport();
        $http->on('GET', '/v2/security/public-key-certificates', RecordedTransport::json(200, $this->certificates()));
        $http->on('POST', '/v2/auth/challenge', RecordedTransport::json(200, ['challenge' => 'c', 'timestamp' => 't', 'timestampMs' => 1, 'clientIp' => 'ip']));
        $http->on('POST', '/v2/auth/ksef-token', RecordedTransport::raw(503, 'down'), RecordedTransport::json(202, []));

        try {
            $this->client($http)->openSession();
            self::fail('expected exception');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::SERVER, $e->kind);
        }
        self::assertSame(1, count(array_filter($http->paths(), static fn ($p) => str_ends_with($p, '/auth/ksef-token'))));
    }

    // ---- step 9: duplicate delivery ---------------------------------------

    public function test_step_9_a_repeated_page_is_returned_verbatim_and_left_to_the_unique_index(): void
    {
        // The client does not dedupe: the database index does, so a retry after
        // a timeout that the server actually processed is safe by construction.
        $http = new RecordedTransport();
        $n = $this->number('dup');
        $row = $this->metadataRow($n, '2026-09-02T08:00:00Z');
        $http->on('POST', '/v2/invoices/query/metadata',
            RecordedTransport::json(200, ['hasMore' => false, 'isTruncated' => false, 'invoices' => [$row, $row]]),
        );

        $page = $this->client($http)->queryInvoices($this->session(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-10'));

        self::assertCount(2, $page->invoices);
        self::assertSame($n, $page->invoices[0]->ksefNumber);
        self::assertSame($n, $page->invoices[1]->ksefNumber);
    }

    // ---- step 10: malformed responses -------------------------------------

    public function test_step_10_non_json_and_missing_required_fields_are_malformed_never_empty(): void
    {
        foreach ([
            RecordedTransport::raw(200, '<html>maintenance</html>'),
            RecordedTransport::json(200, ['invoices' => []]),                                  // no hasMore
            RecordedTransport::json(200, ['hasMore' => false, 'isTruncated' => false]),       // no invoices
            RecordedTransport::json(200, ['hasMore' => 'no', 'isTruncated' => false, 'invoices' => []]),
            RecordedTransport::json(200, ['hasMore' => false, 'isTruncated' => false, 'invoices' => [['ksefNumber' => 'bogus']]]),
            RecordedTransport::json(200, ['hasMore' => false, 'isTruncated' => false, 'invoices' => [array_replace($this->metadataRow($this->number('m'), '2026-09-02T08:00:00Z'), ['grossAmount' => 'many'])]]),
        ] as $i => $response) {
            $http = new RecordedTransport();
            $http->on('POST', '/v2/invoices/query/metadata', $response);
            try {
                $this->client($http)->queryInvoices($this->session(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-10'));
                self::fail('case '.$i.' should have been reported as malformed');
            } catch (KsefTransportException $e) {
                self::assertSame(KsefTransportException::MALFORMED, $e->kind, 'case '.$i);
            }
        }
    }

    public function test_step_10_an_invoice_body_that_is_not_xml_is_reported(): void
    {
        $http = new RecordedTransport();
        $http->on('GET', '/v2/invoices/ksef/', RecordedTransport::raw(200, '{"unexpected":"json"}'));
        $this->expectExceptionMessageMatches('/nie wygląda na XML/u');
        $this->client($http)->fetchInvoiceXml($this->session(), $this->number('nx'));
    }

    // ---- step 11: revoked / expired credentials ---------------------------

    public function test_step_11_a_rejected_token_is_an_auth_failure_with_the_ksef_status_code(): void
    {
        $http = new RecordedTransport();
        $http->on('GET', '/v2/security/public-key-certificates', RecordedTransport::json(200, $this->certificates()));
        $http->on('POST', '/v2/auth/challenge', RecordedTransport::json(200, ['challenge' => 'c', 'timestamp' => 't', 'timestampMs' => 1, 'clientIp' => 'ip']));
        $http->on('POST', '/v2/auth/ksef-token', RecordedTransport::json(202, ['referenceNumber' => 'REF', 'authenticationToken' => ['token' => 'A', 'validUntil' => 'v']]));
        $http->on('GET', '/v2/auth/REF', RecordedTransport::json(200, [
            'startDate' => 's', 'authenticationMethod' => 'Token',
            'authenticationMethodInfo' => ['category' => 'Token', 'code' => 'Token', 'displayName' => 'Token'],
            'status' => ['code' => 400, 'description' => 'Uwierzytelnianie zakończone niepowodzeniem', 'details' => ['Token unieważniony.']],
        ]));

        try {
            $this->client($http)->openSession();
            self::fail('expected exception');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::AUTH_FAILED, $e->kind);
            self::assertTrue($e->isCredentialProblem());
            self::assertSame([400], $e->apiErrorCodes);
            self::assertStringContainsString('Token unieważniony', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        self::assertSame(0, count(array_filter($http->paths(), static fn ($p) => str_contains($p, 'redeem'))), 'no redeem after failure');
    }

    public function test_step_11_an_expired_session_is_refused_locally_before_any_request(): void
    {
        $http = new RecordedTransport();
        $expired = new KsefSession('ACCESS', 'REF', new DateTimeImmutable('2026-09-10T10:00:00Z'), new DateTimeImmutable('2026-09-10T11:00:00Z'));

        try {
            $this->client($http)->fetchInvoiceXml($expired, $this->number('e'));
            self::fail('expected exception');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::UNAUTHORISED, $e->kind);
        }
        self::assertSame([], $http->requests);
    }

    public function test_step_11_a_401_mid_run_is_a_credential_problem(): void
    {
        $http = new RecordedTransport();
        $http->on('GET', '/v2/invoices/ksef/', RecordedTransport::json(401, ['title' => 'Unauthorized', 'status' => 401, 'detail' => 'Token wygasł.', 'timestamp' => 't']));

        try {
            $this->client($http)->fetchInvoiceXml($this->session(), $this->number('u'));
            self::fail('expected exception');
        } catch (KsefTransportException $e) {
            self::assertTrue($e->isCredentialProblem());
            self::assertSame(401, $e->httpStatus);
        }
    }

    // ---- step 12: API version ---------------------------------------------

    public function test_step_12_the_api_version_is_pinned_and_the_error_codes_are_surfaced(): void
    {
        self::assertSame('2.7.1', HttpKsefClient::API_VERSION);
        self::assertSame(100, HttpKsefClient::MAX_WINDOW_DAYS);

        $http = new RecordedTransport();
        $http->on('POST', '/v2/invoices/query/metadata', RecordedTransport::json(400, [
            'title' => 'Bad Request', 'status' => 400, 'instance' => '/v2/invoices/query/metadata', 'detail' => 'Nieprawidłowe żądanie.',
            'errors' => [['code' => 21470, 'description' => 'Nieznany identyfikator klucza publicznego.']],
            'timestamp' => 't', 'traceId' => 'trace-42',
        ]));

        try {
            $this->client($http)->queryInvoices($this->session(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-10'));
            self::fail('expected exception');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::REJECTED, $e->kind);
            self::assertSame([21470], $e->apiErrorCodes);
            self::assertSame('trace-42', $e->traceId);
            self::assertStringContainsString('21470', $e->getMessage());
        }
    }

    // ---- housekeeping -----------------------------------------------------

    public function test_close_session_is_best_effort(): void
    {
        $http = new RecordedTransport();
        $http->on('DELETE', '/v2/auth/sessions/current', RecordedTransport::raw(500, 'x'));
        $this->client($http)->closeSession($this->session());
        self::assertSame('Bearer ACCESS-JWT-eyJ', $http->requests[0]['headers']['Authorization']);
    }

    public function test_the_endpoint_refuses_plain_http_and_a_bad_nip(): void
    {
        $this->expectException(KsefTransportException::class);
        new KsefEndpoint(KsefEnvironment::Test, 'http://ksef-test.invalid/v2', self::NIP);
    }

    public function test_the_endpoint_comes_from_the_egress_registry_and_refuses_a_disabled_entry(): void
    {
        $egress = ['enabled' => false, 'base_urls' => ['test' => 'https://x.invalid/v2']];
        try {
            KsefEndpoint::fromEgressRegistry($egress, KsefEnvironment::Test, self::NIP);
            self::fail('expected refusal');
        } catch (KsefTransportException $e) {
            self::assertSame(KsefTransportException::CONFIG, $e->kind);
            self::assertStringContainsString('egress.ksef.enabled=false', $e->getMessage());
        }

        $endpoint = KsefEndpoint::fromEgressRegistry(['enabled' => true, 'base_urls' => ['test' => 'https://x.invalid/v2/']], KsefEnvironment::Test, self::NIP);
        self::assertSame('https://x.invalid/v2/auth/challenge', $endpoint->url('/auth/challenge'));

        $this->expectExceptionMessageMatches('/nie zna adresu/u');
        KsefEndpoint::fromEgressRegistry(['enabled' => true, 'base_urls' => []], KsefEnvironment::Demo, self::NIP);
    }

    public function test_page_size_outside_the_specification_is_refused(): void
    {
        $this->expectException(KsefTransportException::class);
        new HttpKsefClient(new RecordedTransport(), $this->endpoint(), 't', 500);
    }

    private function session(): KsefSession
    {
        return new KsefSession('ACCESS-JWT-eyJ', 'REF', new DateTimeImmutable('2026-09-10T12:00:00Z'), new DateTimeImmutable('2026-09-10T13:00:00Z'));
    }

    private function decryptOaep(string $ciphertext): string
    {
        $key = openssl_pkey_get_private($this->privateKeyPem);
        $em = '';
        self::assertTrue(openssl_private_decrypt($ciphertext, $em, $key, OPENSSL_NO_PADDING));
        // Inverse of TokenEncryptor::oaepEncode.
        $hLen = 32;
        $maskedSeed = substr($em, 1, $hLen);
        $maskedDb = substr($em, 1 + $hLen);
        $seed = $maskedSeed ^ $this->mgf1($maskedDb, $hLen);
        $db = $maskedDb ^ $this->mgf1($seed, strlen($maskedDb));
        self::assertSame(hash('sha256', '', true), substr($db, 0, $hLen));
        $rest = substr($db, $hLen);
        $pos = strpos($rest, "\x01");
        self::assertNotFalse($pos);
        self::assertSame(str_repeat("\0", $pos), substr($rest, 0, $pos));

        return substr($rest, $pos + 1);
    }

    private function mgf1(string $seed, int $length): string
    {
        $out = '';
        for ($c = 0; strlen($out) < $length; $c++) {
            $out .= hash('sha256', $seed.pack('N', $c), true);
        }

        return substr($out, 0, $length);
    }
}
