<?php

declare(strict_types=1);

/**
 * VAT rates, the subject exemption limit, and filing deadlines.
 */

return [
    // Ustawa o VAT, art. 41 and Annexes 3 and 10. "zw" and "np" are not rates
    // but they must be representable, because a cash register report can carry
    // takings under them and treating them as 0% would understate the ledger.
    'rates_provenance' => [
        'status' => 'secondary',
        'source_document' => 'Ustawa o VAT, art. 41 oraz załączniki nr 3 i 10',
        'source_url' => '',
        'official_source_url' => 'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=WDU20040540535',
        'published_on' => null,
        'checked_on' => '2026-09-07',
        'checked_by' => 'claude-code',
        'notes' => 'Która stawka dotyczy konkretnego towaru lub usługi wynika z załączników i z WIS, '
            .'a nie z tej listy. Lista podaje wyłącznie zbiór dopuszczalnych stawek.',
    ],

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
            'version' => '2025.1',
            'effective_from' => '2025-01',
            'effective_to' => '2025-12',
            // Art. 113 ust. 1 — subject exemption turnover limit.
            'exemption_limit' => '200000.00',
            'meanings' => [
                'exemption_limit' => 'Limit wartości sprzedaży, do którego przysługuje zwolnienie podmiotowe z VAT (art. 113 ust. 1).',
            ],
            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'Ustawa o VAT, art. 113 ust. 1 (limit 200 000 zł)',
                'source_url' => 'https://poradnikprzedsiebiorcy.pl/-limit-zwolnienia-podmiotowego-w-vat',
                'official_source_url' => 'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=WDU20040540535',
                'published_on' => null,
                'checked_on' => '2026-09-07',
                'checked_by' => 'claude-code',
                'notes' => 'Do odczytania wprost z art. 113 ust. 1 ustawy o VAT w brzmieniu na 2025 r.',
            ],
        ],
        [
            'version' => '2026.1',
            'effective_from' => '2026-01',
            'effective_to' => null,
            'exemption_limit' => '240000.00',
            'meanings' => [
                'exemption_limit' => 'Limit zwolnienia podmiotowego z VAT podniesiony do 240 000 zł od 1 stycznia 2026 r.',
            ],
            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'Ustawa z 24 czerwca 2025 r. o zmianie ustawy o podatku od towarów i usług — limit podniesiony do 240 000 zł od 1 stycznia 2026 r.',
                'source_url' => 'https://podatkibezryzyka.pl/zwolnienie-podmiotowe-z-vat/',
                'official_source_url' => 'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=WDU20040540535',
                'published_on' => '2025-06-24',
                'checked_on' => '2026-09-07',
                'checked_by' => 'claude-code',
                'notes' => 'DO POTWIERDZENIA: pozycja w Dzienniku Ustaw dla ustawy zmieniającej '
                    .'i przepisy przejściowe dla podatników, którzy w 2025 r. przekroczyli 200 000 zł, '
                    .'ale nie 240 000 zł.',
            ],
        ],
    ],

    /**
     * Warn the taxpayer while there is still time to act, not after the
     * exemption has already been lost. Expressed as a fraction of the limit.
     */
    'exemption_limit_warning_at' => 0.80,

    'jpk_due_day_of_following_month' => 25,
    'payment_due_day_of_following_month' => 25,

    /**
     * How long after the period in which the right to deduct arose the buyer
     * may still deduct input VAT (ustawa o VAT, art. 86 ust. 11): three
     * following periods for a monthly filer, two for a quarterly one. The right
     * itself arises no earlier than receipt of the invoice (art. 86 ust. 10b);
     * for a KSeF invoice the date of receipt is the date its KSeF number was
     * assigned. Thresholds, so they live here and not in a calculator.
     */
    'input_vat_deduction_following_periods' => 3,
    'input_vat_deduction_following_periods_quarterly' => 2,
];
