<?php

declare(strict_types=1);

/**
 * ZUS health insurance (składka zdrowotna).
 *
 * THE CONTRIBUTION YEAR IS NOT THE CALENDAR YEAR. It runs 1 February to
 * 31 January (ustawa o świadczeniach opieki zdrowotnej, art. 81 ust. 2).
 * January is therefore settled on the PREVIOUS year's figures — the single
 * most common source of a wrong January number, and the reason these versions
 * are dated by month rather than labelled by year.
 *
 * Note for the record: the 2026 reform that would have based the contribution
 * on 75% of the minimum wage was vetoed and did NOT take effect. The rules
 * below are the pre-reform rules, still in force.
 */

return [
    'versions' => [
        [
            'contribution_year' => '2025/2026',
            'effective_from' => '2025-02',
            'effective_to' => '2026-01',

            // Ryczałt: a flat monthly amount chosen by an annual revenue band.
            // 9% of 60% / 100% / 180% of the average wage in the enterprise
            // sector in Q4 of the preceding year (8 549,18 zł for Q4 2024).
            'lump_sum_reference_wage' => '8549.18',
            'lump_sum_bands' => [
                ['revenue_up_to' => '60000.00', 'monthly' => '461.66', 'base_percent_of_reference' => 60],
                ['revenue_up_to' => '300000.00', 'monthly' => '769.43', 'base_percent_of_reference' => 100],
                ['revenue_up_to' => null, 'monthly' => '1384.97', 'base_percent_of_reference' => 180],
            ],

            // Skala podatkowa: 9% of the month's income.
            'scale_rate' => 0.09,
            // Podatek liniowy: 4.9% of the month's income.
            'flat_rate' => 0.049,
            // Floor for both: 9% of the minimum wage in force at the start of
            // the contribution year (4 666 zł from 1 Feb 2025).
            'minimum_monthly' => '419.94',
            'minimum_base' => '4666.00',

            // Annual cap on the health contribution deductible under the flat tax.
            'flat_tax_deduction_limit_annual' => '12900.00',

            'source' => 'Ustawa o świadczeniach opieki zdrowotnej finansowanych ze środków publicznych, art. 79-81; komunikat GUS o przeciętnym wynagrodzeniu w IV kwartale 2024 r.',
            'verified_on' => '2026-09-07',
        ],
        [
            'contribution_year' => '2026/2027',
            'effective_from' => '2026-02',
            'effective_to' => '2027-01',

            // 9% of 60% / 100% / 180% of 9 228,64 zł (Q4 2025).
            'lump_sum_reference_wage' => '9228.64',
            'lump_sum_bands' => [
                ['revenue_up_to' => '60000.00', 'monthly' => '498.35', 'base_percent_of_reference' => 60],
                ['revenue_up_to' => '300000.00', 'monthly' => '830.58', 'base_percent_of_reference' => 100],
                ['revenue_up_to' => null, 'monthly' => '1495.04', 'base_percent_of_reference' => 180],
            ],

            'scale_rate' => 0.09,
            'flat_rate' => 0.049,
            // 9% of 4 806 zł.
            'minimum_monthly' => '432.54',
            'minimum_base' => '4806.00',

            'flat_tax_deduction_limit_annual' => '14100.00',

            'source' => 'Ustawa o świadczeniach opieki zdrowotnej finansowanych ze środków publicznych, art. 79-81; komunikat GUS o przeciętnym wynagrodzeniu w IV kwartale 2025 r. (9 228,64 zł); minimalne wynagrodzenie 2026 r. (4 806 zł).',
            'verified_on' => '2026-09-07',
        ],
    ],

    /**
     * Revenue bands for ryczałt are tested against revenue accumulated in the
     * CALENDAR year (art. 81 ust. 2e). A taxpayer may instead elect the
     * previous year's revenue for the whole year; that is a profile setting,
     * not a default.
     */
    'lump_sum_band_thresholds_are_calendar_year_revenue' => true,
];
