<?php

declare(strict_types=1);

namespace Poland\Tests\Unit\Ksef;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\KsefEnvironment;

/**
 * Gate 3 of docs/KSEF_PRODUCTION_GATE.md: every API base URL the code carries
 * is an explicitly pinned official endpoint, read back from the Ministry's
 * own files in resources/ksef, never derived and never typed from memory.
 */
final class KsefEnvironmentPinTest extends TestCase
{
    private const RESOURCES = __DIR__.'/../../../resources/ksef';

    public function test_the_test_base_url_is_the_one_stated_in_the_pinned_openapi_document(): void
    {
        $openapi = json_decode((string) file_get_contents(self::RESOURCES.'/openapi/open-api-2.7.1-te.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($openapi['servers'][0]['url'], KsefEnvironment::Test->pinnedApiBaseUrl());
    }

    #[DataProvider('officialClientProfiles')]
    public function test_each_base_url_equals_the_official_reference_client_host_plus_the_v2_suffix(KsefEnvironment $environment, string $profileFile): void
    {
        $host = $this->yamlValue(self::RESOURCES.'/environments/ksef-client-java/'.$profileFile, 'base-uri');
        // The suffix is declared once, in the shared profile, and applies to every environment.
        $suffix = $this->yamlValue(self::RESOURCES.'/environments/ksef-client-java/application.yaml', 'suffix-uri');

        self::assertSame('v2', $suffix);
        self::assertSame(rtrim($host, '/').'/'.$suffix, $environment->pinnedApiBaseUrl());
    }

    /** @return iterable<string, array{KsefEnvironment, string}> */
    public static function officialClientProfiles(): iterable
    {
        yield 'test' => [KsefEnvironment::Test, 'application.yaml'];
        yield 'demo' => [KsefEnvironment::Demo, 'application-demo.yaml'];
        yield 'production' => [KsefEnvironment::Production, 'application-prod.yaml'];
    }

    public function test_documentation_urls_are_the_ones_listed_in_the_pinned_environments_document(): void
    {
        $doc = (string) file_get_contents(self::RESOURCES.'/environments/srodowiska.md');

        foreach (KsefEnvironment::cases() as $environment) {
            self::assertStringContainsString($environment->documentationUrl(), $doc, $environment->shortLabel());
        }
    }

    public function test_the_laravel_configuration_defaults_carry_exactly_the_pinned_urls(): void
    {
        $config = (string) file_get_contents(self::RESOURCES.'/../../config/poland.php');

        foreach (KsefEnvironment::cases() as $environment) {
            $count = substr_count($config, "'".$environment->pinnedApiBaseUrl()."'");
            self::assertSame(1, $count, $environment->shortLabel().' must appear exactly once in config/poland.php');
        }
        // Production is a literal with no env() escape hatch.
        self::assertMatchesRegularExpression("/'production'\s*=>\s*'https:\/\/api\.ksef\.mf\.gov\.pl\/v2'/", $config);
    }

    public function test_the_pin_table_and_the_checksums_cover_the_environment_sources(): void
    {
        $pinned = (string) file_get_contents(self::RESOURCES.'/PINNED.md');
        $sums = (string) file_get_contents(self::RESOURCES.'/SHA256SUMS');

        foreach (KsefEnvironment::cases() as $environment) {
            self::assertStringContainsString('`'.$environment->pinnedApiBaseUrl().'`', $pinned);
        }
        foreach (['environments/srodowiska.md', 'environments/ksef-client-java/application.yaml', 'environments/ksef-client-java/application-demo.yaml', 'environments/ksef-client-java/application-prod.yaml'] as $file) {
            $line = null;
            foreach (explode("\n", $sums) as $candidate) {
                if (str_ends_with(trim($candidate), '  '.$file)) {
                    $line = trim($candidate);
                }
            }
            self::assertNotNull($line, $file.' is listed in SHA256SUMS');
            self::assertSame(explode('  ', $line)[0], hash_file('sha256', self::RESOURCES.'/'.$file), $file.' checksum');
        }
        self::assertStringNotContainsStringIgnoringCase('derived', $pinned);
    }

    private function yamlValue(string $file, string $key): string
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').':\s*"?([^"\s]+)"?\s*$/', $line, $m) === 1) {
                return $m[1];
            }
        }
        self::fail(sprintf('%s not found in %s', $key, basename($file)));
    }
}
