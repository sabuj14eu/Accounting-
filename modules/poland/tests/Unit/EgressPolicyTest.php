<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Ksef\Http\KsefTransportException;
use Poland\Ksef\KsefEndpoint;
use Poland\Ksef\KsefEnvironment;

/**
 * Design §14.4: every network destination lives in ONE registry
 * (config/poland.php → egress) with who authorised it and when; the client
 * reads its base URL from there and refuses when the entry is disabled; and
 * no hostname appears anywhere else in module code.
 */
final class EgressPolicyTest extends TestCase
{
    private function config(): array
    {
        // The config file calls env(); provide a shim so it loads outside Laravel.
        if (! function_exists('env')) {
            eval('function env(string $key, mixed $default = null): mixed { $v = getenv($key); return $v === false ? $default : $v; }');
        }

        return require dirname(__DIR__, 2).'/config/poland.php';
    }

    public function test_the_registry_names_every_destination_with_purpose_data_and_authorisation(): void
    {
        $egress = $this->config()['egress'];

        self::assertSame(['ksef', 'nbp'], array_keys($egress), 'a new destination is a deliberate registry entry');
        foreach ($egress as $name => $entry) {
            foreach (['enabled', 'purpose', 'data_sent', 'authorised_by', 'authorised_on', 'base_urls'] as $key) {
                self::assertArrayHasKey($key, $entry, "$name.$key");
            }
            self::assertFalse($entry['enabled'], "$name is disabled by default");
            foreach ($entry['base_urls'] as $url) {
                self::assertStringStartsWith('https://', $url);
            }
        }

        self::assertSame('https://api-test.ksef.mf.gov.pl/v2', $egress['ksef']['base_urls']['test']);
        self::assertSame('https://api-demo.ksef.mf.gov.pl/v2', $egress['ksef']['base_urls']['demo']);
        self::assertSame('https://api.ksef.mf.gov.pl/v2', $egress['ksef']['base_urls']['production']);
        self::assertStringContainsString('InvoiceRead', $egress['ksef']['purpose']);
    }

    public function test_no_hostname_appears_in_module_code_outside_the_egress_registry(): void
    {
        $src = dirname(__DIR__, 2).'/src';
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match_all('~https?://([a-z0-9.-]+\.(?:gov\.pl|pl|com|net|org|io|dev))~i', $code, $m) > 0) {
                $offenders[] = substr($file->getPathname(), strlen($src) + 1).' → '.implode(', ', array_unique($m[1]));
            }
        }

        self::assertSame([], $offenders, "hostnames in code:\n".implode("\n", $offenders));
    }

    public function test_the_client_refuses_when_its_egress_entry_is_disabled(): void
    {
        $egress = $this->config()['egress']['ksef'];
        self::assertFalse($egress['enabled']);

        $this->expectException(KsefTransportException::class);
        $this->expectExceptionMessageMatches('/rejestrze wyjść/u');
        KsefEndpoint::fromEgressRegistry($egress, KsefEnvironment::Test, '5265877635');
    }

    public function test_the_api_version_pin_matches_the_client(): void
    {
        self::assertSame(\Poland\Ksef\HttpKsefClient::API_VERSION, $this->config()['ksef']['api_version']);
    }

    public function test_the_only_reveal_token_call_site_is_the_client_factory(): void
    {
        $src = dirname(__DIR__, 2).'/src';
        $sites = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (str_contains($code, '->revealToken()')) {
                $sites[] = substr($file->getPathname(), strlen($src) + 1);
            }
        }

        self::assertSame(['Laravel/Support/KsefClientFactory.php'], $sites);
    }
}
