<?php

declare(strict_types=1);

/**
 * VAT rates, the subject exemption limit, and filing deadlines.
 */

return [
    // Ustawa o VAT, art. 41 and Annexes 3 and 10. "zw" and "np" are not rates
    // but they must be representable, because a cash register report can carry
    // takings under them and treating them as 0% would understate the ledger.
    'rates' => [
        '0.23' => ['code' => 'A', 'label' => '23% — stawka podstawowa'],
        '0.08' => ['code' => 'B', 'label' => '8% — stawka obniżona'],
        '0.05' => ['code' => 'C', 'label' => '5% — stawka obniżona'],
        '0.00' => ['code' => 'D', 'label' => '0% — stawka zerowa'],
    ],
    'non_rate_designations' => [
        'zw' => 'Zwolnione z VAT (art. 43)',
        'np' => 'Niepodlegające opodatkowaniu',
    ],

    /**
     * Default letter-to-rate mapping used by Polish fiscal cash registers
     * (rozporządzenie MF w sprawie kas rejestrujących). Letters A and B are
     * fixed by the regulation; C-G are assigned by the taxpayer, so this is a
     * DEFAULT that a company profile may override — never an assumption.
     */
    'cash_register_letter_defaults' => [
        'A' => '0.23',
        'B' => '0.08',
        'C' => '0.05',
        'D' => '0.00',
        'E' => 'zw',
    ],

    'versions' => [
        [
            'effective_from' => '2025-01',
            'effective_to' => '2025-12',
            // Art. 113 ust. 1 — subject exemption turnover limit.
            'exemption_limit' => '200000.00',
            'source' => 'Ustawa o VAT, art. 113 ust. 1 (limit 200 000 zł).',
            'verified_on' => '2026-09-07',
        ],
        [
            'effective_from' => '2026-01',
            'effective_to' => null,
            'exemption_limit' => '240000.00',
            'source' => 'Ustawa z 24 czerwca 2025 r. o zmianie ustawy o VAT — limit podniesiony do 240 000 zł od 1 stycznia 2026 r.',
            'verified_on' => '2026-09-07',
        ],
    ],

    /**
     * Warn the taxpayer while there is still time to act, not after the
     * exemption has already been lost. Expressed as a fraction of the limit.
     */
    'exemption_limit_warning_at' => 0.80,

    'jpk_due_day_of_following_month' => 25,
    'payment_due_day_of_following_month' => 25,
];
