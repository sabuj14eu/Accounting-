<?php

declare(strict_types=1);

namespace Poland\Laravel;

use Illuminate\Support\ServiceProvider;
use Poland\Laravel\Console\RateProvenanceCommand;
use Poland\Laravel\Console\ReportCommand;
use Poland\Laravel\Console\VerifyRatesCommand;
use Poland\Laravel\Support\AuditRecorder;
use Poland\Laravel\Support\LedgerRepository;
use Poland\Laravel\Support\MonthlyReportService;
use Poland\Laravel\Support\SettlementRecorder;
use Poland\Rates\RateRepository;
use Poland\Reporting\SettlementEngine;

/**
 * Wires the Poland module into the host accounting application.
 *
 * The module extends the ERP; it does not modify it. Everything it owns is
 * namespaced `pl_` in the database and `poland.` in config and routes, so the
 * upstream Liberu packages can be upgraded without a merge.
 */
final class PolandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/poland.php', 'poland');

        $this->app->singleton(RateRepository::class, function ($app): RateRepository {
            $directory = (string) config('poland.rates_path', dirname(__DIR__, 2).'/config/rates');

            return new RateRepository($directory);
        });

        $this->app->singleton(SettlementEngine::class, fn ($app): SettlementEngine => new SettlementEngine(
            $app->make(RateRepository::class),
            // Production refuses to settle on rates nobody has verified against
            // the issuing authority. Default false so development and the test
            // suite still run; docs/DEPLOYMENT.md requires it true in production.
            (bool) config('poland.require_official_rates', false),
        ));

        // Ports, bound to adapters that REFUSE. The application's shape stays
        // honest: these stages exist and are not implemented, and nothing
        // silently degrades to a plausible default.
        $this->app->bind(
            \Poland\Contracts\ExchangeRateProvider::class,
            \Poland\Adapters\Null\UnavailableExchangeRateProvider::class,
        );

        $this->app->singleton(\Poland\Contracts\SchemaRegistry::class, fn (): \Poland\Contracts\SchemaRegistry => new \Poland\Contracts\SchemaRegistry());

        $this->app->singleton(AuditRecorder::class);
        $this->app->singleton(LedgerRepository::class);

        $this->app->singleton(SettlementRecorder::class, fn ($app): SettlementRecorder => new SettlementRecorder(
            $app->make(SettlementEngine::class),
            $app->make(LedgerRepository::class),
            $app->make(AuditRecorder::class),
        ));

        $this->app->singleton(
            \Poland\Reporting\AccountantReportBuilder::class,
            fn ($app): \Poland\Reporting\AccountantReportBuilder => new \Poland\Reporting\AccountantReportBuilder(
                $app->make(SettlementEngine::class),
            ),
        );

        // Every external system enters through a port bound to an adapter that
        // REFUSES. Nothing degrades to a plausible default: an unreachable KSeF
        // is not "no invoices", and missing OCR is not "no text".
        $this->app->bind(
            \Poland\Ksef\Contracts\KsefClient::class,
            \Poland\Ksef\UnconfiguredKsefClient::class,
        );

        $this->app->bind(
            \Poland\Government\Contracts\TextExtractor::class,
            \Poland\Government\UnavailableTextExtractor::class,
        );

        $this->app->singleton(\Poland\Ksef\Parsing\FaInvoiceParser::class);
        $this->app->singleton(\Poland\Government\DocumentClassifier::class);

        $this->app->singleton(
            \Poland\Reconciliation\TransactionMatcher::class,
            fn ($app): \Poland\Reconciliation\TransactionMatcher
                => new \Poland\Reconciliation\TransactionMatcher(
                    (int) config('poland.reconciliation.date_window_days', 45),
                ),
        );

        // Statement parsers, tried in order. PDF last: it refuses, and it must
        // never shadow a format that can be read exactly.
        $this->app->singleton('poland.statement_parsers', fn (): array => [
            new \Poland\Banking\Parsing\Camt053StatementParser(),
            new \Poland\Banking\Parsing\Mt940StatementParser(),
            new \Poland\Banking\Parsing\CsvStatementParser(),
            new \Poland\Banking\Parsing\PdfStatementParser(),
        ]);

        $this->app->singleton(
            \Poland\Laravel\Support\KsefIngestService::class,
            fn ($app): \Poland\Laravel\Support\KsefIngestService
                => new \Poland\Laravel\Support\KsefIngestService(
                    $app->make(\Poland\Ksef\Contracts\KsefClient::class),
                    $app->make(\Poland\Ksef\Parsing\FaInvoiceParser::class),
                    $app->make(AuditRecorder::class),
                ),
        );

        $this->app->singleton(
            \Poland\Laravel\Support\BankImportService::class,
            fn ($app): \Poland\Laravel\Support\BankImportService
                => new \Poland\Laravel\Support\BankImportService(
                    $app->make('poland.statement_parsers'),
                    $app->make(AuditRecorder::class),
                ),
        );

        $this->app->singleton(MonthlyReportService::class, fn ($app): MonthlyReportService => new MonthlyReportService(
            $app->make(\Poland\Reporting\AccountantReportBuilder::class),
            $app->make(SettlementRecorder::class),
            $app->make(LedgerRepository::class),
            $app->make(AuditRecorder::class),
        ));

        $this->app->singleton(
            \Poland\Laravel\Support\MonthCloseService::class,
            fn ($app): \Poland\Laravel\Support\MonthCloseService
                => new \Poland\Laravel\Support\MonthCloseService(
                    $app->make(\Poland\Laravel\Support\KsefIngestService::class),
                    $app->make(\Poland\Laravel\Support\BankImportService::class),
                    $app->make(MonthlyReportService::class),
                    $app->make(\Poland\Reconciliation\TransactionMatcher::class),
                    $app->make(AuditRecorder::class),
                ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        $this->loadViewsFrom(dirname(__DIR__, 2).'/resources/views', 'poland');

        if (file_exists($routes = dirname(__DIR__, 2).'/routes/web.php')) {
            $this->loadRoutesFrom($routes);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReportCommand::class, VerifyRatesCommand::class, RateProvenanceCommand::class]);

            $this->publishes([
                dirname(__DIR__, 2).'/config/poland.php' => config_path('poland.php'),
            ], 'poland-config');

            $this->publishes([
                dirname(__DIR__, 2).'/config/rates' => config_path('poland-rates'),
            ], 'poland-rates');
        }
    }
}
