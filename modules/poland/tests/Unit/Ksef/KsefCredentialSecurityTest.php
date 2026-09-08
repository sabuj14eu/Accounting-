<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Audit\InMemoryAuditSink;
use Poland\Ksef\Auth\KsefAuthenticator;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Error\Redactor;
use Poland\Ksef\Http\HttpRequest;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\FakeKsefTransport;
use Poland\Ksef\Transport\RealKsefTransport;
use Poland\Tests\Support\KsefFixtures;
use Poland\Tests\Support\ScriptedHttpClient;

/** §9, §38: a credential is never printed, logged, echoed or serialised. */
final class KsefCredentialSecurityTest extends TestCase
{
    private const TOKEN = 'ksef-token-LIVE-9f3b21a0d4e7ffee0011';

    public function test_a_secret_redacts_itself_in_every_rendering(): void
    {
        $secret = Secret::of(self::TOKEN);
        self::assertStringNotContainsString(self::TOKEN, (string) $secret);
        self::assertStringNotContainsString(self::TOKEN, print_r($secret, true));
        self::assertStringNotContainsString(self::TOKEN, var_export($secret->__debugInfo(), true));
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode(['s' => $secret]));
        self::assertStringNotContainsString(self::TOKEN, serialize($secret));
        ob_start();
        var_dump($secret);
        self::assertStringNotContainsString(self::TOKEN, (string) ob_get_clean());
        self::assertSame(self::TOKEN, $secret->reveal());
        self::assertSame(12, strlen($secret->fingerprint()));
    }

    public function test_a_serialised_secret_cannot_be_restored(): void
    {
        $this->expectException(\LogicException::class);
        unserialize(serialize(Secret::of(self::TOKEN)));
    }

    public function test_the_redactor_scrubs_jwts_bearer_headers_and_known_values(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        $text = "Authorization: Bearer $jwt; token=".self::TOKEN.'; KSEF_TOKEN'.'=abc123456789';
        $scrubbed = Redactor::none()->withSecrets([Secret::of(self::TOKEN)])->scrub($text);
        self::assertStringNotContainsString($jwt, $scrubbed);
        self::assertStringNotContainsString(self::TOKEN, $scrubbed);
        self::assertStringNotContainsString('abc123456789', $scrubbed);
        self::assertStringContainsString('[REDACTED', $scrubbed);
    }

    public function test_an_http_request_never_lists_the_authorization_header_as_loggable(): void
    {
        $request = new HttpRequest('GET', 'https://x', ['Accept' => 'application/json'], null, ['Authorization' => 'Bearer '.self::TOKEN]);
        self::assertSame('[REDACTED]', $request->loggableHeaders()['Authorization']);
        self::assertStringNotContainsString(self::TOKEN, var_export($request->loggableHeaders(), true));
        self::assertSame('Bearer '.self::TOKEN, $request->allHeaders()['Authorization'], 'the wire still carries it');
    }

    public function test_the_real_transport_scrubs_an_echoed_bearer_token_from_error_messages(): void
    {
        $http = (new ScriptedHttpClient())->json(400, [
            'title' => 'Bad Request', 'status' => 400, 'detail' => 'echo',
            'errors' => [['code' => 21000, 'description' => 'Invalid header Bearer '.self::TOKEN, 'details' => ['token='.self::TOKEN]]],
        ]);
        $transport = new RealKsefTransport($http, KsefEnvironment::Test, 'https://api-test.ksef.mf.gov.pl/v2');
        try {
            $transport->rateLimits(Secret::of(self::TOKEN));
            self::fail('expected failure');
        } catch (KsefException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, json_encode($e->toArray()) ?: '');
        }
        self::assertStringNotContainsString(self::TOKEN, var_export($http->last()->loggableHeaders(), true));
    }

    public function test_the_ksef_token_never_reaches_the_audit_trail_or_the_fake_transport_log(): void
    {
        $fake = new FakeKsefTransport(now: KsefFixtures::now());
        $audit = new InMemoryAuditSink();
        $context = (new KsefAuthenticator($fake, $audit, 5, 0, static fn (int $s) => null, KsefFixtures::now()))
            ->authenticate(Secret::of(self::TOKEN), KsefFixtures::SELLER_NIP);

        self::assertStringNotContainsString(self::TOKEN, $audit->dump());
        self::assertStringNotContainsString(self::TOKEN, json_encode($fake->calls) ?: '');
        self::assertStringNotContainsString($context->accessToken->token->reveal(), $audit->dump(), 'the access token is a secret too');
        self::assertStringNotContainsString($context->refreshToken->token->reveal(), $audit->dump());
        self::assertStringContainsString($context->referenceNumber, $audit->dump(), 'safe identifiers are recorded');
    }

    public function test_no_exception_in_the_ksef_layer_interpolates_a_revealed_secret(): void
    {
        $root = dirname(__DIR__, 3).'/src/Ksef';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $checked++;
            $code = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression(
                '/(?:Exception|RuntimeException)\([^;]*->reveal\(\)/s',
                $code,
                basename($file->getPathname()).' interpolates a revealed secret into an exception.',
            );
        }
        self::assertGreaterThan(30, $checked);
    }

    public function test_no_credential_value_is_committed(): void
    {
        $root = dirname(__DIR__, 5);
        foreach (['/.env.example', '/modules/poland/config/poland.php', '/bin/deploy-contabo.sh'] as $file) {
            $contents = (string) file_get_contents($root.$file);
            self::assertDoesNotMatchRegularExpression('/KSEF_TOKEN\s*=\s*[\'"]?[A-Za-z0-9_\-]{8,}/', $contents, $file);
        }
    }
}
