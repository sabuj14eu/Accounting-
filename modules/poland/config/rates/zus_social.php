<?php

declare(strict_types=1);

/**
 * ZUS social insurance (składki społeczne) for a sole trader paying only their
 * own contributions.
 *
 * Structure: contribution RATES are stable across years and live once; the
 * BASES change every January and are versioned. Nothing here is referenced from
 * code by year — the calculator asks the table for the version covering a
 * period and refuses to run if none exists.
 *
 * PROVENANCE. Every version records where its numbers came from, where they
 * must be confirmed, and how far they can be trusted. The figures below are
 * currently `secondary`: taken from competent Polish accounting sources and
 * cross-checked arithmetically against their own stated formulas (the tests in
 * RateTableTest verify, for example, that the full base really is 60% of the
 * stated forecast wage). That is a consistency check, not an official source.
 * Until somebody confirms them against the ZUS and Dziennik Ustaw publications
 * named below and flips the status to `official`, a production installation
 * with `poland.require_official_rates` enabled will REFUSE to settle.
 */

return [
    'component_rates' => [
        // Ustawa o systemie ubezpieczeń społecznych, art. 22 ust. 1.
        'pension' => 0.1952,        // emerytalne
        'disability' => 0.0800,     // rentowe
        'sickness' => 0.0245,       // chorobowe — VOLUNTARY for an entrepreneur
        // Accident insurance is payer-specific. 1.67% is the statutory rate for
        // a payer reporting no more than 9 insured persons, which is every
        // one-person JDG. A payer with 10+ insured must override it.
        'accident' => 0.0167,       // wypadkowe
        'labour_fund' => 0.0245,    // Fundusz Pracy + Fundusz Solidarnościowy
    ],

    'component_rates_provenance' => [
        'status' => 'secondary',
        'source_document' => 'Ustawa o systemie ubezpieczeń społecznych, art. 22 ust. 1; rozporządzenie MPiPS w sprawie różnicowania stopy procentowej składki wypadkowej',
        'source_url' => '',
        'official_source_url' => 'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=WDU19981370887',
        'published_on' => null,
        'checked_on' => '2026-09-07',
        'checked_by' => 'claude-code — kontrola arytmetyczna względem opublikowanych sum składek',
        'notes' => 'Stopy składek nie zmieniały się od lat, ale nie zostały odczytane wprost z ustawy. '
            .'Stopa wypadkowa 1,67% dotyczy płatnika zgłaszającego do 9 ubezpieczonych.',
    ],

    /**
     * Fundusz Pracy is due only when the contribution base reaches the minimum
     * wage (ustawa o promocji zatrudnienia, art. 104b/104). This is why the
     * preferential scheme pays no FP: its base is 30% of the minimum wage.
     * Encoded as a rule rather than baked into per-scheme totals.
     */
    'labour_fund_requires_base_at_least_minimum_wage' => true,

    'versions' => [
        [
            'version' => '2025.1',
            'effective_from' => '2025-01',
            'effective_to' => '2025-12',

            'minimum_wage' => '4666.00',
            'forecast_average_wage' => '8673.00',
            'full_base' => '5203.80',
            'preferential_base' => '1399.80',
            'maly_zus_plus_min_base' => '1399.80',
            'maly_zus_plus_max_base' => '5203.80',

            'meanings' => [
                'minimum_wage' => 'Minimalne wynagrodzenie za pracę w 2025 r. Wyznacza podstawę preferencyjną i próg obowiązku Funduszu Pracy.',
                'forecast_average_wage' => 'Prognozowane przeciętne wynagrodzenie miesięczne w gospodarce narodowej na 2025 r. z ustawy budżetowej.',
                'full_base' => '60% prognozowanego przeciętnego wynagrodzenia — podstawa pełnych składek społecznych.',
                'preferential_base' => '30% minimalnego wynagrodzenia — podstawa składek preferencyjnych przez pierwsze 24 miesiące.',
                'maly_zus_plus_min_base' => 'Dolna granica podstawy w Małym ZUS Plus (równa podstawie preferencyjnej).',
                'maly_zus_plus_max_base' => 'Górna granica podstawy w Małym ZUS Plus (równa podstawie pełnej).',
            ],

            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'Obwieszczenie ZUS o wysokości składek na 2025 r.; ustawa budżetowa na 2025 r.; rozporządzenie RM w sprawie minimalnego wynagrodzenia w 2025 r.',
                'source_url' => 'https://poradnikprzedsiebiorcy.pl/-wskazniki-preferencyjne-skladki-zus',
                'official_source_url' => 'https://www.zus.pl/baza-wiedzy/skladki-wskazniki-odsetki/skladki/wysokosc-skladek-na-ubezpieczenia-spoleczne',
                'published_on' => null,
                'checked_on' => '2026-09-07',
                'checked_by' => 'claude-code — kontrola arytmetyczna',
                'notes' => 'Suma pełnych składek 1 773,96 zł i preferencyjnych 442,90 zł odtwarza się '
                    .'dokładnie z tych podstaw i stóp (pinned w ZusCalculatorTest). Wymaga potwierdzenia '
                    .'w komunikacie ZUS oraz w Dz.U. dla minimalnego wynagrodzenia.',
            ],
        ],
        [
            'version' => '2026.1',
            'effective_from' => '2026-01',
            'effective_to' => '2026-12',

            'minimum_wage' => '4806.00',
            'forecast_average_wage' => '9420.00',
            'full_base' => '5652.00',
            'preferential_base' => '1441.80',
            'maly_zus_plus_min_base' => '1441.80',
            'maly_zus_plus_max_base' => '5652.00',

            'meanings' => [
                'minimum_wage' => 'Minimalne wynagrodzenie za pracę w 2026 r. (4 806 zł).',
                'forecast_average_wage' => 'Prognozowane przeciętne wynagrodzenie miesięczne na 2026 r. (9 420 zł) z ustawy budżetowej.',
                'full_base' => '60% prognozowanego przeciętnego wynagrodzenia — podstawa pełnych składek społecznych.',
                'preferential_base' => '30% minimalnego wynagrodzenia — podstawa składek preferencyjnych.',
                'maly_zus_plus_min_base' => 'Dolna granica podstawy w Małym ZUS Plus.',
                'maly_zus_plus_max_base' => 'Górna granica podstawy w Małym ZUS Plus.',
            ],

            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'Ustawa budżetowa na 2026 r. (prognozowane przeciętne wynagrodzenie 9 420 zł); rozporządzenie RM w sprawie minimalnego wynagrodzenia w 2026 r. (4 806 zł)',
                'source_url' => 'https://ksiegowosc.infor.pl/zus-kadry/skladki/7500894,skladki-zus-przedsiebiorcow-w-2026-r-zwykle-preferencyjne-maly-zus-plus-kwoty-i-minimalne-podstawy-wymiaru.html',
                'official_source_url' => 'https://www.zus.pl/baza-wiedzy/skladki-wskazniki-odsetki/skladki/wysokosc-skladek-na-ubezpieczenia-spoleczne',
                'published_on' => null,
                'checked_on' => '2026-09-07',
                'checked_by' => 'claude-code — kontrola arytmetyczna',
                'notes' => 'Suma pełnych składek 1 926,76 zł i preferencyjnych 456,18 zł (420,86 zł bez '
                    .'chorobowego) odtwarza się dokładnie z tych podstaw i stóp. Różnica preferencyjnych '
                    .'2026 vs 2025 wynosi 13,28 zł, co zgadza się z podawaną publicznie. Wymaga '
                    .'potwierdzenia w komunikacie ZUS i w Dz.U.',
            ],
        ],
    ],
];
