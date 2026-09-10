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
    // `?:` rather than a default argument: .env.example ships the key EMPTY
    // (POLAND_RATES_PATH=), and env() returns '' for an empty value, which the
    // repository then reports as "Rate directory not found: ". Empty means
    // "use the module's own tables".
    'rates_path' => env('POLAND_RATES_PATH') ?: dirname(__DIR__).'/config/rates',

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

        /*
         * Stage C: the real transport. Written against KSeF API 2.0, OpenAPI
         * 2.7.1 (CIRFMF/ksef-docs). The version is pinned here AND in
         * Poland\Ksef\HttpKsefClient::API_VERSION; `poland:ksef-check` fails
         * when they disagree, so an upgrade is a deliberate two-place change.
         */
        'api_version' => '2.7.1',
        'page_size' => (int) env('KSEF_PAGE_SIZE', 100),          // spec: 10..250
        'max_retries' => (int) env('KSEF_MAX_RETRIES', 3),
        'connect_timeout_seconds' => (int) env('KSEF_CONNECT_TIMEOUT', 10),
        'timeout_seconds' => (int) env('KSEF_TIMEOUT', 60),
        // Leave empty to use the system CA store.
        'ca_bundle' => env('KSEF_CA_BUNDLE'),
        // Scheduler cadence for `poland:ksef-sync`. The API's minimum cyclic
        // interval is 15 minutes; a one-person shop needs far less.
        'sync_every_minutes' => (int) env('KSEF_SYNC_EVERY_MINUTES', 120),
        // KSEF_TRANSPORT=fake reads FA XML files from here (non-production only).
        'fake_fixtures_dir' => env('KSEF_FAKE_FIXTURES_DIR') ?: dirname(__DIR__).'/tests/Fixtures',
    ],

    /*
     * THE EGRESS REGISTRY. Every network destination this application may
     * talk to, in one place, each with who authorised it and when. The real
     * KSeF client reads its base URL from here and refuses if the entry is
     * disabled; a test asserts no hostname appears anywhere else in the
     * module. Adding a destination is a deliberate entry here — never a URL
     * in code, never a library that phones home.
     *
     * Base URLs are the official ones from CIRFMF/ksef-docs/srodowiska.md
     * (2026-03-16) and are configuration, not constants: the Ministry has
     * moved them before.
     */
    'egress' => [
        'ksef' => [
            'enabled' => (bool) env('KSEF_EGRESS_ENABLED', false),
            'purpose' => 'Pobieranie faktur zakupowych podatnika z KSeF (tylko odczyt, InvoiceRead).',
            'data_sent' => 'NIP podatnika, zaszyfrowany token KSeF, zakres dat zapytania, numery KSeF pobieranych faktur. Nic więcej.',
            'authorised_by' => env('KSEF_EGRESS_AUTHORISED_BY'),
            'authorised_on' => env('KSEF_EGRESS_AUTHORISED_ON'),
            'base_urls' => [
                'test' => env('KSEF_BASE_URL_TEST', 'https://api-test.ksef.mf.gov.pl/v2'),
                'demo' => env('KSEF_BASE_URL_DEMO', 'https://api-demo.ksef.mf.gov.pl/v2'),
                'production' => env('KSEF_BASE_URL_PRODUCTION', 'https://api.ksef.mf.gov.pl/v2'),
            ],
        ],
        'nbp' => [
            'enabled' => (bool) env('NBP_ENABLED', false),
            'purpose' => 'Kursy walut NBP (tabela A) do przeliczeń faktur walutowych.',
            'data_sent' => 'Data i kod waluty. Nic więcej.',
            'authorised_by' => env('NBP_AUTHORISED_BY'),
            'authorised_on' => env('NBP_AUTHORISED_ON'),
            'base_urls' => ['default' => env('NBP_BASE_URL', 'https://api.nbp.pl/api')],
        ],
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
