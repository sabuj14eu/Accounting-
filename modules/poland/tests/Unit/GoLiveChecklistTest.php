<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\FakeKsefClient;
use Poland\Ksef\GoLiveChecklist;
use Poland\Ksef\HttpKsefClient;
use Poland\Ksef\KsefEndpoint;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\KsefNumber;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Tests\Support\RecordedTransport;

/**
 * The checklist runner itself: honest verdicts, no PASS by default, and the
 * fake client (KSEF_TRANSPORT=fake) behaving like an inbox with pages.
 */
final class GoLiveChecklistTest extends TestCase
{
    private const NIP = '5265877635';

    private string $certDer = '';

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'ksef'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);
        $this->certDer = base64_encode((string) base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem) ?? '', true));
    }

    private function endpoint(): KsefEndpoint
    {
        return new KsefEndpoint(KsefEnvironment::Test, 'https://ksef-test.invalid/v2', self::NIP);
    }

    private function authStatus(int $code, array $details = []): array
    {
        return [
            'startDate' => 's', 'authenticationMethod' => 'Token',
            'authenticationMethodInfo' => ['category' => 'Token', 'code' => 'Token', 'displayName' => 'Token'],
            'status' => ['code' => $code, 'description' => $code === 200 ? 'ok' : 'Uwierzytelnianie zakończone niepowodzeniem', 'details' => $details],
        ];
    }

    private function scriptEnvironment(RecordedTransport $http, array $invoices, string $liveVersion = '2.7.1'): void
    {
        $certs = [['certificate' => $this->certDer, 'certificateId' => 'c', 'publicKeyId' => 'PK-1', 'validFrom' => '2026-01-01T00:00:00Z', 'validTo' => '2027-01-01T00:00:00Z', 'usage' => ['KsefTokenEncryption']]];
        // probe + real auth + wrong-token auth = three certificate reads
        $http->on('GET', '/v2/security/public-key-certificates', RecordedTransport::json(200, $certs), RecordedTransport::json(200, $certs), RecordedTransport::json(200, $certs));
        $challenge = RecordedTransport::json(200, ['challenge' => 'c', 'timestamp' => 't', 'timestampMs' => 1, 'clientIp' => 'ip']);
        $http->on('POST', '/v2/auth/challenge', $challenge, $challenge);
        $http->on('POST', '/v2/auth/ksef-token',
            RecordedTransport::json(202, ['referenceNumber' => 'REF-GOOD', 'authenticationToken' => ['token' => 'A', 'validUntil' => 'v']]),
            RecordedTransport::json(202, ['referenceNumber' => 'REF-BAD', 'authenticationToken' => ['token' => 'B', 'validUntil' => 'v']]),
        );
        $http->on('GET', '/v2/auth/REF-GOOD', RecordedTransport::json(200, $this->authStatus(200)));
        $http->on('GET', '/v2/auth/REF-BAD', RecordedTransport::json(200, $this->authStatus(400, ['Nieprawidłowy token.'])));
        $http->on('POST', '/v2/auth/token/redeem', RecordedTransport::json(200, ['accessToken' => ['token' => 'ACCESS', 'validUntil' => '2026-09-10T13:00:00Z'], 'refreshToken' => ['token' => 'R', 'validUntil' => 'v']]));
        $http->on('DELETE', '/v2/auth/sessions/current', RecordedTransport::raw(204, ''));

        // step 5 (1-day), steps 6/7 (window), step 9 (window again)
        $page = static fn (array $rows, bool $more) => RecordedTransport::json(200, ['hasMore' => $more, 'isTruncated' => false, 'invoices' => $rows]);
        $http->on('POST', '/v2/invoices/query/metadata', $page([], false), $page($invoices, false), $page($invoices, false));

        $http->on('GET', '/v2/invoices/ksef/', RecordedTransport::raw(200, (string) file_get_contents(dirname(__DIR__).'/Fixtures/fa2-incoming.xml')));
        $http->on('GET', '/docs/v2/open-api.json', RecordedTransport::json(200, ['info' => ['description' => '**Wersja API:** '.$liveVersion.' (build x)']]));
    }

    private function row(string $seed): array
    {
        $n = KsefNumber::synthetic('1234563218', new DateTimeImmutable('2026-09-02'), $seed);

        return [
            'ksefNumber' => $n, 'invoiceNumber' => 'F/'.$seed, 'issueDate' => '2026-09-01', 'invoicingDate' => '2026-09-02T08:00:00Z',
            'acquisitionDate' => '2026-09-02T08:00:00Z', 'permanentStorageDate' => '2026-09-02T08:00:00Z',
            'seller' => ['nip' => '1234563218', 'name' => 'S'], 'buyer' => ['identifier' => ['type' => 'Nip', 'value' => self::NIP], 'name' => 'B'],
            'netAmount' => 100.0, 'grossAmount' => 123.0, 'vatAmount' => 23.0, 'currency' => 'PLN', 'invoicingMode' => 'Online', 'invoiceType' => 'Vat',
            'formCode' => ['systemCode' => 'FA (2)', 'schemaVersion' => '1-0E', 'value' => 'FA'], 'isSelfInvoicing' => false, 'hasAttachment' => false, 'invoiceHash' => 'h',
        ];
    }

    private function checklist(RecordedTransport $http, string $pinned = '2.7.1'): GoLiveChecklist
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-10T12:00:00Z');
        $sleep = static function (int $s): void {};

        return new GoLiveChecklist(
            fn (int $pageSize, ?string $token): HttpKsefClient => new HttpKsefClient($http, $this->endpoint(), $token ?? 'GOOD-TOKEN-0123456789', $pageSize, 0, $clock, $sleep),
            $http,
            $this->endpoint(),
            new FaInvoiceParser(),
            $pinned,
            45,
            new DateTimeImmutable('2026-09-10T12:00:00Z'),
        );
    }

    public function test_a_healthy_environment_with_one_invoice_passes_everything_it_can_and_says_what_it_could_not(): void
    {
        $http = new RecordedTransport();
        $this->scriptEnvironment($http, [$this->row('one')]);

        $results = $this->checklist($http)->run();

        self::assertCount(12, $results);
        $verdicts = array_column($results, 'verdict', 'step');
        self::assertSame(GoLiveChecklist::PASS, $verdicts[1]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[2]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[3]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[4]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[5]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[6]);
        self::assertSame(GoLiveChecklist::NOT_TESTED, $verdicts[7], 'one invoice cannot exercise paging');
        self::assertSame(GoLiveChecklist::NOT_TESTED, $verdicts[8]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[9]);
        self::assertSame(GoLiveChecklist::NOT_TESTED, $verdicts[10]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[11]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[12]);

        self::assertFalse(GoLiveChecklist::allPassed($results), 'NOT TESTED is never PASS');
        self::assertSame('NOT TESTED 3, PASS 9', GoLiveChecklist::summary($results));
        self::assertStringContainsString('PK-1', $results[2]['observed']);
        self::assertStringContainsString('REF-GOOD', $results[3]['observed']);
        self::assertStringContainsString('bajtów XML', $results[5]['observed']);
        self::assertStringContainsString('identyczne', $results[8]['observed']);
        self::assertStringContainsString('Nieprawidłowy token', $results[10]['observed']);

        // The wrong-token probe really used a different token, and nothing leaked it.
        $inits = array_values(array_filter($http->requests, static fn ($r) => str_ends_with($r['url'], '/auth/ksef-token')));
        self::assertCount(2, $inits);
        self::assertNotSame($inits[0]['body'], $inits[1]['body']);
        foreach ($results as $r) {
            self::assertStringNotContainsString('GOOD-TOKEN', $r['observed']);
        }
    }

    public function test_an_empty_test_inbox_is_not_tested_not_passed(): void
    {
        $http = new RecordedTransport();
        $this->scriptEnvironment($http, []);

        $verdicts = array_column($this->checklist($http)->run(), 'verdict', 'step');

        self::assertSame(GoLiveChecklist::NOT_TESTED, $verdicts[6]);
        self::assertSame(GoLiveChecklist::NOT_TESTED, $verdicts[7]);
        self::assertSame(GoLiveChecklist::NOT_TESTED, $verdicts[9]);
        self::assertSame(GoLiveChecklist::PASS, $verdicts[11]);
    }

    public function test_a_rejected_token_fails_step_4_and_marks_the_rest_not_runnable(): void
    {
        $http = new RecordedTransport();
        $certs = [['certificate' => $this->certDer, 'certificateId' => 'c', 'publicKeyId' => 'PK-1', 'validFrom' => '2026-01-01T00:00:00Z', 'validTo' => '2027-01-01T00:00:00Z', 'usage' => ['KsefTokenEncryption']]];
        $http->on('GET', '/v2/security/public-key-certificates', RecordedTransport::json(200, $certs), RecordedTransport::json(200, $certs), RecordedTransport::json(200, $certs));
        $challenge = RecordedTransport::json(200, ['challenge' => 'c', 'timestamp' => 't', 'timestampMs' => 1, 'clientIp' => 'ip']);
        $http->on('POST', '/v2/auth/challenge', $challenge, $challenge);
        $http->on('POST', '/v2/auth/ksef-token',
            RecordedTransport::json(202, ['referenceNumber' => 'REF-1', 'authenticationToken' => ['token' => 'A', 'validUntil' => 'v']]),
            RecordedTransport::json(202, ['referenceNumber' => 'REF-2', 'authenticationToken' => ['token' => 'B', 'validUntil' => 'v']]),
        );
        $http->on('GET', '/v2/auth/REF-', RecordedTransport::json(200, $this->authStatus(400, ['Token unieważniony.'])), RecordedTransport::json(200, $this->authStatus(400, ['Token unieważniony.'])));

        $results = $this->checklist($http)->run();
        $verdicts = array_column($results, 'verdict', 'step');

        self::assertSame(GoLiveChecklist::FAIL, $verdicts[4]);
        self::assertStringContainsString('Token unieważniony', $results[3]['observed']);
        foreach ([5, 6, 7, 8, 9, 10, 12] as $step) {
            self::assertSame(GoLiveChecklist::NOT_RUNNABLE, $verdicts[$step], 'step '.$step);
        }
        self::assertSame(GoLiveChecklist::PASS, $verdicts[11], 'a wrong token being rejected is still evidence');
    }

    public function test_a_version_mismatch_is_a_failure_of_step_12_and_step_2(): void
    {
        $http = new RecordedTransport();
        $this->scriptEnvironment($http, [$this->row('one')], liveVersion: '2.8.0');

        $verdicts = array_column($this->checklist($http, pinned: '2.6.0')->run(), 'verdict', 'step');

        self::assertSame(GoLiveChecklist::FAIL, $verdicts[2]);
        self::assertSame(GoLiveChecklist::FAIL, $verdicts[12]);
    }

    public function test_the_fake_client_pages_dedupes_nothing_and_needs_an_open_session(): void
    {
        $parser = new FaInvoiceParser();
        $fixtures = dirname(__DIR__).'/Fixtures';
        $fake = FakeKsefClient::fromXmlFiles([$fixtures.'/fa2-incoming.xml', $fixtures.'/fa2-drinks-invoice.xml', $fixtures.'/fa2-correction.xml'], $parser, 2);

        self::assertSame(3, $fake->count());
        $session = $fake->openSession();
        self::assertStringStartsWith('FAKE-', $session->reference);

        $from = new DateTimeImmutable('2000-01-01');
        $to = new DateTimeImmutable('2100-01-01');
        $first = $fake->queryInvoices($session, $from, $to);
        self::assertCount(2, $first->invoices);
        self::assertTrue($first->hasMore());
        $second = $fake->queryInvoices($session, $from, $to, $first->nextCursor);
        self::assertCount(1, $second->invoices);
        self::assertFalse($second->hasMore());

        $all = [...$first->invoices, ...$second->invoices];
        foreach ($all as $m) {
            self::assertTrue(KsefNumber::isValid($m->ksefNumber));
            self::assertStringContainsString('<', $fake->fetchInvoiceXml($session, $m->ksefNumber));
        }
        self::assertCount(3, array_unique(array_map(static fn ($m) => $m->ksefNumber, $all)));

        $fake->closeSession($session);
        $this->expectExceptionMessageMatches('/nie jest otwarta/u');
        $fake->queryInvoices($session, $from, $to);
    }
}
