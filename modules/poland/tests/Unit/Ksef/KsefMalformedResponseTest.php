<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Http\HttpFailure;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\InvoiceQuery;
use Poland\Ksef\Transport\RealKsefTransport;
use Poland\Tests\Support\KsefFixtures;
use Poland\Tests\Support\ScriptedHttpClient;

/** §22, §25: a response the transport cannot read is MALFORMED with an unknown outcome — never an empty result. */
final class KsefMalformedResponseTest extends TestCase
{
    private function transport(ScriptedHttpClient $http): RealKsefTransport
    {
        return new RealKsefTransport($http, KsefEnvironment::Test, 'https://api-test.ksef.mf.gov.pl/v2');
    }

    private function query(): InvoiceQuery
    {
        return InvoiceQuery::incremental('Subject2', new DateTimeImmutable('2026-08-01'));
    }

    public function test_invalid_json_is_malformed_with_unknown_outcome(): void
    {
        $http = (new ScriptedHttpClient())->raw(200, '<html>maintenance</html>', 'text/html');
        try {
            $this->transport($http)->queryInvoiceMetadata(Secret::of('t'), $this->query(), 0, 10);
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::MalformedResponse, $e->category);
            self::assertFalse($e->outcomeKnown);
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('przegląd ręczny', $e->getMessage());
        }
    }

    public function test_a_page_without_its_required_fields_is_malformed(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['invoices' => []]);
        try {
            $this->transport($http)->queryInvoiceMetadata(Secret::of('t'), $this->query(), 0, 10);
            self::fail('expected failure: hasMore/isTruncated missing');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::MalformedResponse, $e->category);
            self::assertStringContainsString('hasMore', $e->getMessage());
        }
    }

    public function test_an_invoice_with_a_bad_ksef_number_poisons_the_whole_page(): void
    {
        $http = (new ScriptedHttpClient())->json(200, ['hasMore' => false, 'isTruncated' => false, 'permanentStorageHwmDate' => '2026-09-01T00:00:00Z', 'invoices' => [
            ['ksefNumber' => '5265877635-20250826-0100001AF629-00', 'invoiceNumber' => 'X', 'seller' => ['nip' => '5265877635'], 'buyer' => ['identifier' => ['type' => 'Nip', 'value' => '3861610227']]],
        ]]);
        try {
            $this->transport($http)->queryInvoiceMetadata(Secret::of('t'), $this->query(), 0, 10);
            self::fail('expected failure: wrong CRC');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::MalformedResponse, $e->category);
            self::assertStringContainsString('suma kontrolna', $e->getMessage());
        }
    }

    public function test_a_valid_page_is_parsed_into_typed_metadata(): void
    {
        $number = KsefFixtures::ksefNumber(1);
        $http = (new ScriptedHttpClient())->json(200, ['hasMore' => true, 'isTruncated' => false, 'permanentStorageHwmDate' => '2026-09-01T12:00:00+00:00', 'invoices' => [[
            'ksefNumber' => $number, 'invoiceNumber' => 'FV/1', 'issueDate' => '2026-08-30', 'invoicingDate' => '2026-08-30T10:00:00Z', 'acquisitionDate' => '2026-08-30T10:00:01Z', 'permanentStorageDate' => '2026-08-30T10:00:05Z',
            'seller' => ['nip' => '1234567890', 'name' => 'Dostawca'], 'buyer' => ['identifier' => ['type' => 'Nip', 'value' => '5265877635'], 'name' => 'My'],
            'netAmount' => 100.5, 'vatAmount' => 23.12, 'grossAmount' => 123.62, 'currency' => 'PLN', 'invoiceType' => 'Vat', 'invoicingMode' => 'Online', 'invoiceHash' => 'abc=', 'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'], 'isSelfInvoicing' => false, 'hasAttachment' => false,
        ]]]);
        $page = $this->transport($http)->queryInvoiceMetadata(Secret::of('t'), $this->query(), 3, 10);
        self::assertTrue($page->hasMore);
        self::assertSame(3, $page->pageOffset);
        self::assertSame('2026-09-01T12:00:00+00:00', $page->permanentStorageHwmDate?->format(DATE_ATOM));
        $m = $page->invoices[0];
        self::assertSame($number, $m->ksefNumber);
        self::assertSame('100,50 zł', $m->net?->format());
        self::assertSame('5265877635', $m->buyerNip);
        self::assertTrue($m->isIncomingFor('5265877635'));
        $request = $http->last();
        self::assertStringContainsString('pageOffset=3', $request->url);
        self::assertStringContainsString('sortOrder=Asc', $request->url);
        self::assertStringContainsString('"restrictToPermanentStorageHwmDate":true', (string) $request->body);
        self::assertSame('problem-details', $request->headers['X-Error-Format']);
    }

    public function test_a_non_xml_body_for_an_invoice_is_malformed(): void
    {
        $http = (new ScriptedHttpClient())->raw(200, '{"unexpected":"json"}', 'application/json');
        try {
            $this->transport($http)->invoiceXml(Secret::of('t'), KsefFixtures::ksefNumber(1));
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::MalformedResponse, $e->category);
        }
    }

    public function test_an_empty_200_body_where_json_is_required_is_malformed(): void
    {
        $http = (new ScriptedHttpClient())->raw(200, '', 'application/json');
        $this->expectException(KsefException::class);
        $this->transport($http)->authChallenge();
    }

    public function test_a_too_large_response_is_malformed_not_truncated(): void
    {
        $http = (new ScriptedHttpClient())->enqueue(new HttpFailure(HttpFailure::TOO_LARGE, 'over limit', true));
        try {
            $this->transport($http)->invoiceXml(Secret::of('t'), KsefFixtures::ksefNumber(1));
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::MalformedResponse, $e->category);
            self::assertFalse($e->outcomeKnown);
        }
    }

    public function test_every_documented_error_shape_is_read(): void
    {
        // problem-details (current)
        $http = (new ScriptedHttpClient())->json(400, ['title' => 'Bad Request', 'status' => 400, 'detail' => 'Żądanie jest nieprawidłowe.', 'errors' => [['code' => 21405, 'description' => 'Błąd walidacji danych wejściowych.', 'details' => ['Wskazany kod formularza nie jest wspierany.']]]]);
        $e = $this->fail404($http);
        self::assertSame(21405, $e->ksefCode);
        self::assertSame(['Wskazany kod formularza nie jest wspierany.'], $e->details);
        self::assertSame(KsefErrorCategory::ValidationError, $e->category);

        // deprecated exception shape
        $http = (new ScriptedHttpClient())->json(400, ['exception' => ['exceptionDetailList' => [['exceptionCode' => 21301, 'exceptionDescription' => 'Sesja nie istnieje.']]]]);
        $e = $this->fail404($http);
        self::assertSame(21301, $e->ksefCode);
        self::assertStringContainsString('Sesja nie istnieje', $e->getMessage());

        // 429 with Retry-After
        $http = (new ScriptedHttpClient())->json(429, ['status' => ['code' => 429, 'description' => 'Too Many Requests', 'details' => ['Przekroczono limit 20 żądań na minutę.']]], ['retry-after' => '30']);
        $e = $this->fail404($http);
        self::assertSame(KsefErrorCategory::RateLimit, $e->category);
        self::assertSame(30, $e->retryAfterSeconds);
        self::assertTrue($e->isRetryable());

        // 5xx
        $http = (new ScriptedHttpClient())->raw(503, 'Service Unavailable', 'text/plain');
        $e = $this->fail404($http);
        self::assertSame(KsefErrorCategory::ServerError, $e->category);
        self::assertTrue($e->isRetryable());
    }

    private function fail404(ScriptedHttpClient $http): KsefException
    {
        try {
            $this->transport($http)->sessionStatus(Secret::of('t'), 'S');
        } catch (KsefException $e) {
            return $e;
        }
        self::fail('expected a KsefException');
    }
}
