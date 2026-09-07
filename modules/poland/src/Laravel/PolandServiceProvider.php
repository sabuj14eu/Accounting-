<?php

declare(strict_types=1);

namespace Poland\Laravel;

use Illuminate\Support\ServiceProvider;
use Poland\Laravel\Console\RateProvenanceCommand;
use Poland\Laravel\Console\ReportCommand;
use Poland\Laravel\Console\VerifyRatesCommand;
use Poland\Laravel\Support\AuditRecorder;
use Poland\Laravel\Support\LedgerRepository;
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
