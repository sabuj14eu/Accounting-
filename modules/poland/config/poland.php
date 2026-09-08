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
         * throws rather than degrading. Five modes: DISABLED · FAKE · TEST ·
         * DEMO · PRODUCTION (Poland\Ksef\TransportGate::mode()).
         */
        'transport_enabled' => (bool) env('KSEF_TRANSPORT_ENABLED', false),
        'transport' => env('KSEF_TRANSPORT', 'disabled'),   // disabled | fake | real
        'environment' => env('KSEF_ENVIRONMENT', 'test'),   // test | demo | production

        /*
         * Official API base URLs per environment, pinned verbatim from Ministry
         * material (resources/ksef/PINNED.md, "Environments"): the OpenAPI
         * document for TEST, the official reference client's profiles for
         * DEMO and PRODUCTION. KsefEnvironmentPinTest fails if these literals
         * drift from the pinned files. KSEF_BASE_URL may override TEST or DEMO
         * (a proxy, a mirror); it can NEVER override production —
         * KsefEndpoints refuses.
         */
        'base_urls' => [
            'test' => env('KSEF_TEST_BASE_URL', 'https://api-test.ksef.mf.gov.pl/v2'),
            'demo' => env('KSEF_DEMO_BASE_URL', 'https://api-demo.ksef.mf.gov.pl/v2'),
            'production' => 'https://api.ksef.mf.gov.pl/v2',
        ],
        'base_url' => env('KSEF_BASE_URL'),

        // The contract this code was written against. Displayed, audited, pinned.
        'api_version' => '2.7.1',
        'nip' => env('KSEF_NIP'),

        // SystemInfo in the FA(3) header: the issuing software's name.
        'system_info' => env('KSEF_SYSTEM_INFO', 'SignalMesh Accounts'),

        // Every request has an explicit connect and total timeout. Seconds.
        'timeouts' => [
            'connect' => (int) env('KSEF_CONNECT_TIMEOUT', 10),
            'request' => (int) env('KSEF_REQUEST_TIMEOUT', 30),
            'upload' => (int) env('KSEF_UPLOAD_TIMEOUT', 60),
            'download' => (int) env('KSEF_DOWNLOAD_TIMEOUT', 60),
            'max_response_bytes' => (int) env('KSEF_MAX_RESPONSE_BYTES', 5_000_000),
        ],
        // Optional CA bundle for the HTTPS client (system default when empty).
        'ca_bundle' => env('KSEF_CA_BUNDLE'),

        'retry' => [
            'max_attempts' => (int) env('KSEF_RETRY_MAX_ATTEMPTS', 4),
            'base_delay_seconds' => (int) env('KSEF_RETRY_BASE_DELAY', 2),
            'max_delay_seconds' => (int) env('KSEF_RETRY_MAX_DELAY', 60),
        ],
        'auth' => [
            'poll_attempts' => (int) env('KSEF_AUTH_POLL_ATTEMPTS', 20),
            'poll_delay_seconds' => (int) env('KSEF_AUTH_POLL_DELAY', 1),
            // A successful authentication older than this no longer counts as
            // "connected" on the status page; it becomes NOT VERIFIED.
            'freshness_hours' => (int) env('KSEF_AUTH_FRESHNESS_HOURS', 24),
        ],
        'submission' => [
            // How long the interactive send waits for a final status before
            // leaving the invoice PROCESSING for the scheduled poll.
            'poll_attempts' => (int) env('KSEF_SUBMISSION_POLL_ATTEMPTS', 6),
            'poll_delay_seconds' => (int) env('KSEF_SUBMISSION_POLL_DELAY', 5),
        ],
        'sync' => [
            // Incremental retrieval per subject type; the buyer role first.
            'subject_types' => ['Subject2', 'Subject1'],
            'page_size' => (int) env('KSEF_SYNC_PAGE_SIZE', 100),
            'max_pages_per_run' => (int) env('KSEF_SYNC_MAX_PAGES', 50),
            // How many days back the FIRST sync of a profile looks.
            'lookback_days' => (int) env('KSEF_LOOKBACK_DAYS', 45),
            // Scheduled sync is opt-in; manual "Synchronizuj teraz" always works.
            'schedule_enabled' => (bool) env('KSEF_SYNC_SCHEDULE_ENABLED', false),
            'schedule_time' => env('KSEF_SYNC_SCHEDULE_TIME', '03:15'),
            // Seconds between two invoice-body fetches (rate limit: 8/s, 16/min).
            'fetch_delay_seconds' => (int) env('KSEF_FETCH_DELAY', 1),
        ],
        // Kept for compatibility with the pre-2.0 ingest API.
        'max_invoices_per_run' => (int) env('KSEF_MAX_INVOICES_PER_RUN', 500),
        'lookback_days' => (int) env('KSEF_LOOKBACK_DAYS', 45),
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
