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
 * below are the pre-reform rules. That, too, needs confirming against the
 * Dziennik Ustaw before these figures are used for a filing — a reform that
 * "was vetoed" according to the press is exactly the kind of fact that must be
 * checked at the source.
 */

return [
    'versions' => [
        [
            'version' => '2025-02.1',
            'contribution_year' => '2025/2026',
            'effective_from' => '2025-02',
            'effective_to' => '2026-01',

            'lump_sum_reference_wage' => '8549.18',
            'lump_sum_bands' => [
                ['revenue_up_to' => '60000.00', 'monthly' => '461.66', 'base_percent_of_reference' => 60],
                ['revenue_up_to' => '300000.00', 'monthly' => '769.43', 'base_percent_of_reference' => 100],
                ['revenue_up_to' => null, 'monthly' => '1384.97', 'base_percent_of_reference' => 180],
            ],

            'scale_rate' => 0.09,
            'flat_rate' => 0.049,
            'minimum_monthly' => '419.94',
            'minimum_base' => '4666.00',
            'flat_tax_deduction_limit_annual' => '12900.00',

            'meanings' => [
                'lump_sum_reference_wage' => 'Przeciętne miesięczne wynagrodzenie w sektorze przedsiębiorstw w IV kwartale 2024 r., włącznie z wypłatami z zysku (komunikat GUS). Podstawa progów ryczałtowych.',
                'lump_sum_bands' => 'Trzy progi składki zdrowotnej na ryczałcie: 9% z 60%, 100% i 180% wynagrodzenia odniesienia, wybierane według przychodu narastająco od 1 stycznia.',
                'scale_rate' => '9% dochodu miesiąca poprzedzającego — skala podatkowa.',
                'flat_rate' => '4,9% dochodu miesiąca poprzedzającego — podatek liniowy.',
                'minimum_monthly' => 'Składka minimalna: 9% minimalnego wynagrodzenia obowiązującego na początek roku składkowego (1.02.2025).',
                'minimum_base' => 'Minimalne wynagrodzenie stanowiące podstawę składki minimalnej.',
                'flat_tax_deduction_limit_annual' => 'Roczny limit odliczenia zapłaconej składki zdrowotnej na podatku liniowym.',
            ],

            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'Ustawa o świadczeniach opieki zdrowotnej finansowanych ze środków publicznych, art. 79-81; komunikat Prezesa GUS o przeciętnym wynagrodzeniu w IV kwartale 2024 r.',
                'source_url' => 'https://poradnikprzedsiebiorcy.pl/-skladka-zdrowotna-ryczaltowcow',
                'official_source_url' => 'https://www.zus.pl/baza-wiedzy/skladki-wskazniki-odsetki/skladki/wysokosc-skladki-na-ubezpieczenie-zdrowotne',
                'published_on' => null,
                'checked_on' => '2026-09-07',
                'checked_by' => 'claude-code — kontrola arytmetyczna',
                'notes' => 'Każdy próg odtwarza się dokładnie jako 9% podanego procentu wynagrodzenia '
                    .'odniesienia (test w RateTableTest). Do potwierdzenia: komunikat GUS za IV kw. 2024 r. '
                    .'oraz limit odliczenia 12 900 zł.',
            ],
        ],
        [
            'version' => '2026-02.1',
            'contribution_year' => '2026/2027',
            'effective_from' => '2026-02',
            'effective_to' => '2027-01',

            'lump_sum_reference_wage' => '9228.64',
            'lump_sum_bands' => [
                ['revenue_up_to' => '60000.00', 'monthly' => '498.35', 'base_percent_of_reference' => 60],
                ['revenue_up_to' => '300000.00', 'monthly' => '830.58', 'base_percent_of_reference' => 100],
                ['revenue_up_to' => null, 'monthly' => '1495.04', 'base_percent_of_reference' => 180],
            ],

            'scale_rate' => 0.09,
            'flat_rate' => 0.049,
            'minimum_monthly' => '432.54',
            'minimum_base' => '4806.00',
            'flat_tax_deduction_limit_annual' => '14100.00',

            'meanings' => [
                'lump_sum_reference_wage' => 'Przeciętne miesięczne wynagrodzenie w sektorze przedsiębiorstw w IV kwartale 2025 r. (9 228,64 zł).',
                'lump_sum_bands' => 'Progi składki zdrowotnej na ryczałcie w roku składkowym 2026/2027.',
                'scale_rate' => '9% dochodu miesiąca poprzedzającego — skala podatkowa.',
                'flat_rate' => '4,9% dochodu miesiąca poprzedzającego — podatek liniowy.',
                'minimum_monthly' => 'Składka minimalna: 9% z 4 806 zł.',
                'minimum_base' => 'Minimalne wynagrodzenie 2026 r.',
                'flat_tax_deduction_limit_annual' => 'Roczny limit odliczenia składki zdrowotnej na podatku liniowym w 2026 r.',
            ],

            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'Ustawa o świadczeniach opieki zdrowotnej, art. 79-81; komunikat Prezesa GUS o przeciętnym wynagrodzeniu w IV kwartale 2025 r. (9 228,64 zł)',
                'source_url' => 'https://symfonia.pl/blog/rozwoj-firmy/jdg/skladka-zdrowotna-2025-ryczalt/',
                'official_source_url' => 'https://www.zus.pl/baza-wiedzy/skladki-wskazniki-odsetki/skladki/wysokosc-skladki-na-ubezpieczenie-zdrowotne',
                'published_on' => null,
                'checked_on' => '2026-09-07',
                'checked_by' => 'claude-code — kontrola arytmetyczna',
                'notes' => 'DO POTWIERDZENIA URZĘDOWEGO, trzy rzeczy: (1) komunikat GUS za IV kw. 2025 r., '
                    .'(2) że reforma składki zdrowotnej (9% z 75% minimalnego wynagrodzenia) rzeczywiście '
                    .'nie weszła w życie na 2026 r., (3) limit odliczenia 14 100 zł. Punkt (2) jest '
                    .'krytyczny: gdyby reforma jednak obowiązywała, wszystkie kwoty w tej wersji są błędne.',
            ],
        ],
    ],

    'lump_sum_band_thresholds_are_calendar_year_revenue' => true,
];
