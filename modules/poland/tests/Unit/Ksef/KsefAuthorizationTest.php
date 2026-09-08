<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\KsefScope;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\RealKsefTransport;
use Poland\Tests\Support\ScriptedHttpClient;

/** Permission failures are safe: classified, not retried, never read as "nothing there". */
final class KsefAuthorizationTest extends TestCase
{
    private function transport(ScriptedHttpClient $http): RealKsefTransport
    {
        return new RealKsefTransport($http, KsefEnvironment::Test, 'https://api-test.ksef.mf.gov.pl/v2');
    }

    public function test_http_403_is_an_authorization_error_and_not_retryable(): void
    {
        $http = (new ScriptedHttpClient())->json(403, ['title' => 'Forbidden', 'status' => 403, 'detail' => 'Brak uprawnień', 'errors' => [['code' => 21403, 'description' => 'Brak uprawnienia InvoiceWrite']]]);
        try {
            $this->transport($http)->sendInvoice(Secret::of('t'), 'S', 'h', 1, 'h', 1, 'c');
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::AuthorizationError, $e->category);
            self::assertFalse($e->isRetryable());
            self::assertSame(21403, $e->ksefCode);
            self::assertTrue($e->outcomeKnown, 'a 403 proves nothing was accepted');
        }
    }

    public function test_http_401_is_an_authentication_error(): void
    {
        $http = (new ScriptedHttpClient())->json(401, ['title' => 'Unauthorized', 'status' => 401, 'detail' => 'Token wygasł', 'errors' => []]);
        try {
            $this->transport($http)->queryInvoiceMetadata(Secret::of('expired'), \Poland\Ksef\Transport\Dto\InvoiceQuery::incremental('Subject2', new \DateTimeImmutable('2026-09-01')), 0, 10);
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertSame(KsefErrorCategory::AuthenticationError, $e->category);
            self::assertFalse($e->isRetryable());
        }
    }

    public function test_a_permission_failure_on_the_invoice_list_never_becomes_an_empty_page(): void
    {
        $http = (new ScriptedHttpClient())->json(403, ['title' => 'Forbidden', 'status' => 403, 'detail' => 'x', 'errors' => []]);
        $this->expectException(KsefException::class);
        $this->transport($http)->queryInvoiceMetadata(Secret::of('t'), \Poland\Ksef\Transport\Dto\InvoiceQuery::incremental('Subject2', new \DateTimeImmutable('2026-09-01')), 0, 10);
    }

    public function test_the_scopes_the_product_needs_are_named_and_compared(): void
    {
        self::assertSame(['InvoiceWrite'], KsefScope::missingFrom(['InvoiceRead', 'Introspection']));
        self::assertSame([], KsefScope::missingFrom(['InvoiceRead', 'InvoiceWrite']));
        self::assertSame(['InvoiceRead', 'InvoiceWrite'], KsefScope::missingFrom([]));
    }

    public function test_credential_management_scopes_are_refused_by_the_application(): void
    {
        $this->expectException(\RuntimeException::class);
        KsefScope::CredentialsManage->assertAllowed();
    }
}
