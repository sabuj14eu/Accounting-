<?php

declare(strict_types=1);

/**
 * ZUS social insurance (składki społeczne) for a sole trader paying only their
 * own contributions.
 *
 * Structure: contribution RATES are stable across years and live once; the
 * BASES change every January and are versioned by effective period. Nothing
 * here is referenced from code by year — the calculator asks the table for the
 * version covering a period and refuses to run if none exists.
 *
 * Every version carries where its numbers came from. A number without a source
 * is a number nobody can re-check.
 */

return [
    'component_rates' => [
        // Ustawa o systemie ubezpieczeń społecznych, art. 22 ust. 1.
        'pension' => 0.1952,        // emerytalne
        'disability' => 0.0800,     // rentowe
        'sickness' => 0.0245,       // chorobowe — VOLUNTARY for an entrepreneur
        // Accident insurance is payer-specific. 1.67% is the statutory rate for
        // a payer reporting no more than 9 insured persons (rozporządzenie MPiPS),
        // which is every one-person JDG. A payer with 10+ insured must override it.
        'accident' => 0.0167,       // wypadkowe
        'labour_fund' => 0.0245,    // Fundusz Pracy + Fundusz Solidarnościowy
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
            'effective_from' => '2025-01',
            'effective_to' => '2025-12',
            'minimum_wage' => '4666.00',
            'forecast_average_wage' => '8673.00',
            // 60% of the forecast average monthly wage.
            'full_base' => '5203.80',
            // 30% of the minimum wage.
            'preferential_base' => '1399.80',
            // Mały ZUS Plus is bounded by the preferential base and the full base.
            'maly_zus_plus_min_base' => '1399.80',
            'maly_zus_plus_max_base' => '5203.80',
            'source' => 'Obwieszczenie MRPiPS w sprawie kwoty ograniczenia rocznej podstawy wymiaru składek na 2025 r.; Rozporządzenie RM w sprawie minimalnego wynagrodzenia w 2025 r.',
            'verified_on' => '2026-09-07',
        ],
        [
            'effective_from' => '2026-01',
            'effective_to' => '2026-12',
            'minimum_wage' => '4806.00',
            'forecast_average_wage' => '9420.00',
            'full_base' => '5652.00',
            'preferential_base' => '1441.80',
            'maly_zus_plus_min_base' => '1441.80',
            'maly_zus_plus_max_base' => '5652.00',
            'source' => 'Ustawa budżetowa na 2026 r. (prognozowane przeciętne wynagrodzenie 9 420 zł); Rozporządzenie RM w sprawie minimalnego wynagrodzenia w 2026 r. (4 806 zł).',
            'verified_on' => '2026-09-07',
        ],
    ],
];
