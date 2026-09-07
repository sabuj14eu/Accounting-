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
     * KSeF. Base URLs are configuration, never constants: the Ministry has
     * moved them before and a hard-coded host becomes a silent outage.
     *
     * The scope is InvoiceRead and only InvoiceRead. Issuing invoices in the
     * taxpayer's name is a different act and needs a deliberate code change,
     * not a config edit — see Poland\Ksef\KsefScope::allowed().
     */
    'ksef' => [
        'enabled' => (bool) env('KSEF_ENABLED', false),

        /*
         * The transport gate. A failed production connection must say
         * "KSeF synchronization unavailable" — never "no invoices found",
         * which is a claim about the taxpayer's month rather than the system's
         * state. Production may not select the fake transport at all; the gate
         * throws rather than degrading.
         */
        'transport_enabled' => (bool) env('KSEF_TRANSPORT_ENABLED', false),
        'transport' => env('KSEF_TRANSPORT', 'disabled'),   // disabled | fake | real
        'environment' => env('KSEF_ENVIRONMENT', 'test'),
        'base_url' => env('KSEF_BASE_URL'),
        'nip' => env('KSEF_NIP'),
        'scope' => 'InvoiceRead',
        // How many days back a routine sync looks. Wider than a month so a run
        // missed while the server was down still catches up.
        'lookback_days' => (int) env('KSEF_LOOKBACK_DAYS', 45),
        'max_invoices_per_run' => (int) env('KSEF_MAX_INVOICES_PER_RUN', 500),
    ],

    'reconciliation' => [
        // How far apart a payment and its document may be and still be
        // considered for a match.
        'date_window_days' => (int) env('POLAND_MATCH_WINDOW_DAYS', 45),
        // A suggestion below this is never booked automatically.
        'auto_book_confidence' => (float) env('POLAND_AUTO_BOOK_CONFIDENCE', 0.9),
    ],

    /*
     * Presentation locale for the module's screens and reports.
     */
    'locale' => env('POLAND_LOCALE', 'pl'),
    'currency' => 'PLN',
];
