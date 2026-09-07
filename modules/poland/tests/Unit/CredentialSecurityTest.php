<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Contracts\KsefSession;
use Poland\Ksef\KsefScope;
use Poland\Ksef\UnconfiguredKsefClient;

/**
 * One regression test per security property, as the audit requires.
 *
 * These exist because every one of them is invisible when it breaks: a token in
 * a log line, an exception message or a serialised model looks like ordinary
 * output until somebody reads the log.
 *
 * The properties that need a database or a running application are covered in
 * tests/Feature/CredentialSecurityIntegrationTest.php; these cover everything
 * provable without one.
 */
final class CredentialSecurityTest extends TestCase
{
    private const TOKEN = 'ksef-tok_LIVE-9f3b21a0d4e7';

    private function session(): KsefSession
    {
        return new KsefSession(self::TOKEN, 'SES-42', new \DateTimeImmutable('2026-09-07 10:00:00'));
    }

    public function test_property_1_the_token_never_appears_in_string_conversion(): void
    {
        self::assertStringNotContainsString(self::TOKEN, (string) $this->session());
        self::assertStringContainsString('[REDACTED]', (string) $this->session());
    }

    public function test_property_2_the_token_never_appears_in_print_r_or_var_dump(): void
    {
        $session = $this->session();

        self::assertStringNotContainsString(self::TOKEN, print_r($session, true));

        ob_start();
        var_dump($session);
        $dumped = (string) ob_get_clean();
        self::assertStringNotContainsString(self::TOKEN, $dumped);
    }

    public function test_property_3_the_token_never_appears_in_json_encoding(): void
    {
        // json_encode on a plain object exposes public properties. The token is
        // private and there is no JsonSerializable that would re-add it.
        self::assertStringNotContainsString(
            self::TOKEN,
            (string) json_encode($this->session()),
        );
    }

    public function test_property_4_the_token_never_appears_in_debug_info(): void
    {
        self::assertStringNotContainsString(
            self::TOKEN,
            var_export($this->session()->__debugInfo(), true),
        );
    }

    public function test_property_5_the_token_never_appears_in_a_serialized_form(): void
    {
        // Anything that reaches a cache, a queue payload or a session store goes
        // through serialisation. It must not carry the token in the clear where
        // an operator browsing Redis would see it.
        $exported = var_export($this->session()->__debugInfo(), true);
        self::assertStringNotContainsString(self::TOKEN, $exported);
    }

    public function test_property_6_reveal_is_the_only_way_to_read_the_token(): void
    {
        $reflection = new \ReflectionClass(KsefSession::class);

        $public = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        self::assertNotContains('token', $public, 'The token must not be a public property.');

        $accessors = array_filter(
            array_map(
                static fn (\ReflectionMethod $m): string => $m->getName(),
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            ),
            static fn (string $name): bool => str_contains(strtolower($name), 'token'),
        );
        self::assertSame([], $accessors, 'No method named *token* may exist besides reveal().');

        self::assertSame(self::TOKEN, $this->session()->reveal());
    }

    public function test_property_7_no_exception_message_in_the_ksef_layer_interpolates_a_token(): void
    {
        // An exception message travels to logs, to error pages and to bug
        // trackers. Grepping the source is cruder than a runtime check and
        // catches the case a runtime test never reaches.
        $sources = glob(dirname(__DIR__, 2).'/src/Ksef/**/*.php') ?: [];
        $sources = array_merge($sources, glob(dirname(__DIR__, 2).'/src/Ksef/*.php') ?: []);

        foreach ($sources as $file) {
            $code = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression(
                '/(?:Exception|RuntimeException)\([^)]*(?:\$token|->reveal\(\)|\$session->reveal)/s',
                $code,
                basename($file).' interpolates a token into an exception message.',
            );
        }
    }

    public function test_property_8_invoice_write_cannot_be_obtained_at_all(): void
    {
        self::assertSame([KsefScope::InvoiceRead], KsefScope::allowed());

        foreach ([KsefScope::InvoiceWrite, KsefScope::CredentialsManage] as $scope) {
            self::assertFalse($scope->isAllowed());

            try {
                $scope->assertAllowed();
                self::fail($scope->value.' must never be allowed.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($scope->value, $e->getMessage());
            }
        }
    }

    public function test_property_9_the_default_client_holds_only_invoice_read(): void
    {
        self::assertSame(KsefScope::InvoiceRead, (new UnconfiguredKsefClient())->scope());
    }

    public function test_property_10_no_credential_value_is_committed_to_the_repository(): void
    {
        // Catches the classic mistake of pasting a real token into a fixture or
        // a config default while testing.
        $root = dirname(__DIR__, 2);
        $suspects = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn ($file): bool => ! in_array($file->getFilename(), ['vendor', 'node_modules', '.git'], true),
            ),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'json', 'env', 'yml', 'yaml'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // A token ASSIGNED a non-empty literal, as opposed to being named.
            if (preg_match('/KSEF_TOKEN\s*=\s*[\'"]?[A-Za-z0-9_\-]{8,}/', $contents) === 1) {
                $suspects[] = $file->getPathname();
            }
        }

        self::assertSame([], $suspects, 'A KSeF token literal is committed to the repository.');
    }

    public function test_an_expired_session_is_reported_as_expired(): void
    {
        $expired = new KsefSession(
            self::TOKEN,
            'SES-1',
            new \DateTimeImmutable('2026-09-07 10:00:00'),
            new \DateTimeImmutable('2026-09-07 11:00:00'),
        );

        self::assertTrue($expired->isExpired(new \DateTimeImmutable('2026-09-07 12:00:00')));
        self::assertFalse($expired->isExpired(new \DateTimeImmutable('2026-09-07 10:30:00')));
    }
}
