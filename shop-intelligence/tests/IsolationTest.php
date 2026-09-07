<?php

declare(strict_types=1);

namespace Shop\Tests;

use PHPUnit\Framework\TestCase;

/**
 * §28 — the isolation audit, as tests rather than as a paragraph.
 *
 * The shell guard (bin/check-shop-isolation.sh) scans the whole tree including
 * config and deployment files. This suite covers what the shell cannot see:
 * that the loaded PHP classes themselves have no reference to the accounting
 * engine, the trading system, or any credential store.
 */
final class IsolationTest extends TestCase
{
    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__).'/src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_domain_has_source_files_to_check(): void
    {
        $this->assertGreaterThan(20, count($this->sourceFiles()));
    }

    /** No import of the accounting engine anywhere. */
    public function test_no_class_references_the_accounting_engine(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/\buse\s+Poland\\\\/',
                $contents,
                basename($file).' imports the accounting engine. §2: the two applications share no code.',
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\bPoland\\\\(Domain|Calculators|Rates|Reporting|Ksef|Laravel)\b/',
                $contents,
                basename($file).' names an accounting engine namespace.',
            );
        }
    }

    /** No KSeF, no filing, no government submission — §22 and §28. */
    public function test_nothing_touches_ksef_filing_or_a_government_endpoint(): void
    {
        $forbidden = '/\b(KsefClient|ksef_token|ksefToken|JpkV7|submitToKsef|e-?Deklaracje|gov\.pl|mf\.gov)\b/i';

        foreach ($this->sourceFiles() as $file) {
            $contents = file_get_contents($file);

            // The AI boundary names KSeF in order to forbid it; that line is the
            // prohibition, not a violation, so comments and enum labels are read
            // out and the rest of the file is scanned.
            $code = preg_replace('/^\s*(\*|\/\/|#).*$/m', '', $contents);
            $code = preg_replace("/'[^']*'/", "''", (string) $code);

            $this->assertDoesNotMatchRegularExpression(
                $forbidden,
                (string) $code,
                basename($file).' reaches toward KSeF or a filing endpoint.',
            );
        }
    }

    /** No trading system, in any of its names — §28. */
    public function test_nothing_references_the_trading_system(): void
    {
        $forbidden = '/\b(MT5|MetaTrader|SniperExecutor|brother_sniper|brother-brain|signalmesh_trading|brain\.signalmesh)\b/i';

        foreach ($this->sourceFiles() as $file) {
            $code = preg_replace('/^\s*(\*|\/\/|#).*$/m', '', file_get_contents($file));

            $this->assertDoesNotMatchRegularExpression(
                $forbidden,
                (string) $code,
                basename($file).' references the trading system.',
            );
        }
    }

    /** No database connection is opened from the analysis core at all. */
    public function test_the_analysis_core_opens_no_database_connection(): void
    {
        $forbidden = '/\b(new\s+PDO|mysqli_connect|pg_connect|DB::|Eloquent|Illuminate\\\\)/';

        foreach ($this->sourceFiles() as $file) {
            $code = preg_replace('/^\s*(\*|\/\/|#).*$/m', '', file_get_contents($file));

            $this->assertDoesNotMatchRegularExpression(
                $forbidden,
                (string) $code,
                basename($file).' opens a database connection from the framework-free core.',
            );
        }
    }

    /** No HTTP client: this application calls nothing, so it cannot call the accounts. */
    public function test_the_analysis_core_makes_no_outbound_calls(): void
    {
        $forbidden = '/\b(curl_init|file_get_contents\s*\(\s*[\'"]https?:|fsockopen|stream_socket_client|GuzzleHttp)\b/';

        foreach ($this->sourceFiles() as $file) {
            $code = preg_replace('/^\s*(\*|\/\/|#).*$/m', '', file_get_contents($file));

            $this->assertDoesNotMatchRegularExpression(
                $forbidden,
                (string) $code,
                basename($file).' makes an outbound call.',
            );
        }
    }

    /**
     * The only route in from the accounting side is AccountsSnapshot, and it is
     * a container that somebody fills by hand.
     */
    public function test_the_only_accounts_input_is_a_hand_imported_snapshot(): void
    {
        $comparison = dirname(__DIR__).'/src/Comparison';
        $files = array_values(array_diff(scandir($comparison), ['.', '..']));

        sort($files);
        $this->assertSame(['AccountsComparison.php', 'AccountsSnapshot.php'], $files);

        // Comments are stripped first: this file describes what it refuses to
        // do, and a check that cannot tell a prohibition from a violation gets
        // switched off within a week.
        $snapshot = preg_replace(
            ['/\/\*.*?\*\//s', '/^\s*(\/\/|#).*$/m'],
            '',
            file_get_contents($comparison.'/AccountsSnapshot.php'),
        );

        foreach (['function fetch', 'function pull', 'function load', 'connect', 'query'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                (string) $snapshot,
                "AccountsSnapshot reaches for the accounting system ('{$needle}').",
            );
        }
    }
}
