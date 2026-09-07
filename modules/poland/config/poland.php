<?php

declare(strict_types=1);

return [
    /*
     * Where the versioned rate tables live.
     *
     * Publish them into the application (`php artisan vendor:publish
     * --tag=poland-rates`) and point this at the published copy when a rate has
     * to be updated between releases of this module. Updating rates must never
     * require editing code.
     */
    'rates_path' => env('POLAND_RATES_PATH', dirname(__DIR__).'/config/rates'),

    /*
     * Refuse to settle a month using rate tables that have not been verified
     * against the issuing authority's own publication.
     *
     * MUST be true in production. It is the mechanism that stops a figure
     * sourced from the accounting press being handed to somebody as an amount
     * to pay. `php artisan poland:rate-provenance --todo` lists what is left.
     */
    'require_official_rates' => (bool) env('POLAND_REQUIRE_OFFICIAL_RATES', false),

    /*
     * Route prefix and middleware for the module's own screens. The accounting
     * application authenticates its users itself; no credential and no session
     * is ever shared with the trading platform.
     */
    'routes' => [
        'enabled' => env('POLAND_ROUTES_ENABLED', true),
        'prefix' => env('POLAND_ROUTE_PREFIX', 'poland'),
        'middleware' => ['web', 'auth'],
    ],

    /*
     * Presentation locale for the module's screens and reports.
     */
    'locale' => env('POLAND_LOCALE', 'pl'),
    'currency' => 'PLN',
];
