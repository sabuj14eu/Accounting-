<?php

declare(strict_types=1);

/**
 * PIT parameters for a sole trader (JDG).
 *
 * The scale parameters have not moved since July 2022, but they are still
 * versioned: a table that only gets versions when something changes is a table
 * nobody remembers to version.
 */

return [
    'versions' => [
        [
            'version' => '2025.1',
            'effective_from' => '2025-01',
            'effective_to' => '2025-12',
            'scale' => [
                'tax_free_allowance' => '30000.00',   // kwota wolna od podatku
                'tax_reducing_amount' => '3600.00',   // kwota zmniejszająca podatek (12% x 30 000)
                'first_threshold' => '120000.00',     // I próg podatkowy
                'first_rate' => 0.12,
                'second_rate' => 0.32,
            ],
            'flat' => [
                'rate' => 0.19,
            ],
            'solidarity_levy' => [
                'threshold' => '1000000.00',
                'rate' => 0.04,
                // Annual (PIT-DSF), never part of a monthly advance.
                'monthly_advance' => false,
            ],
            'meanings' => [
                'scale.tax_free_allowance' => 'Kwota wolna od podatku w skali podatkowej.',
                'scale.tax_reducing_amount' => 'Kwota zmniejszająca podatek (12% z kwoty wolnej), odejmowana od podatku narastająco.',
                'scale.first_threshold' => 'Górna granica pierwszego progu podatkowego; nadwyżka opodatkowana stawką 32%.',
                'scale.first_rate' => 'Stawka pierwszego progu.',
                'scale.second_rate' => 'Stawka drugiego progu.',
                'flat.rate' => 'Stawka podatku liniowego.',
                'solidarity_levy.threshold' => 'Próg daniny solidarnościowej; danina jest roczna i nie wchodzi do zaliczki miesięcznej.',
                'solidarity_levy.rate' => 'Stawka daniny solidarnościowej od nadwyżki ponad próg.',
            ],
            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'Ustawa o podatku dochodowym od osób fizycznych, art. 27 ust. 1, art. 30c, art. 30h',
                'source_url' => 'https://www.pit.pl/skala-podatkowa-pit/',
                'official_source_url' => 'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=WDU19910800350',
                'published_on' => null,
                'checked_on' => '2026-09-07',
                'checked_by' => 'claude-code — kontrola arytmetyczna',
                'notes' => 'Parametry skali nie zmieniały się od lipca 2022 r. Do odczytania wprost z art. 27 ust. 1 ustawy o PIT.',
            ],
        ],
        [
            'version' => '2026.1',
            'effective_from' => '2026-01',
            'effective_to' => null,
            'scale' => [
                'tax_free_allowance' => '30000.00',
                'tax_reducing_amount' => '3600.00',
                'first_threshold' => '120000.00',
                'first_rate' => 0.12,
                'second_rate' => 0.32,
            ],
            'flat' => [
                'rate' => 0.19,
            ],
            'solidarity_levy' => [
                'threshold' => '1000000.00',
                'rate' => 0.04,
                'monthly_advance' => false,
            ],
            'meanings' => [
                'scale.tax_free_allowance' => 'Kwota wolna od podatku w skali podatkowej.',
                'scale.tax_reducing_amount' => 'Kwota zmniejszająca podatek (12% z kwoty wolnej), odejmowana od podatku narastająco.',
                'scale.first_threshold' => 'Górna granica pierwszego progu podatkowego; nadwyżka opodatkowana stawką 32%.',
                'scale.first_rate' => 'Stawka pierwszego progu.',
                'scale.second_rate' => 'Stawka drugiego progu.',
                'flat.rate' => 'Stawka podatku liniowego.',
                'solidarity_levy.threshold' => 'Próg daniny solidarnościowej; danina jest roczna i nie wchodzi do zaliczki miesięcznej.',
                'solidarity_levy.rate' => 'Stawka daniny solidarnościowej od nadwyżki ponad próg.',
            ],
            'provenance' => [
                'status' => 'secondary',
                'source_document' => 'Ustawa o PIT, art. 27 ust. 1, art. 30c, art. 30h — parametry skali niezmienione względem 2025 r.',
                'source_url' => 'https://www.pit.pl/skala-podatkowa-pit/',
                'official_source_url' => 'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=WDU19910800350',
                'published_on' => null,
                'checked_on' => '2026-09-07',
                'checked_by' => 'claude-code — kontrola arytmetyczna',
                'notes' => 'DO POTWIERDZENIA: że na 2026 r. nie zmieniono kwoty wolnej ani progu. '
                    .'Brak zmiany jest twierdzeniem o stanie prawnym i wymaga sprawdzenia tak samo jak zmiana.',
            ],
        ],
    ],

    /**
     * Ryczałt rates (ustawa o zryczałtowanym podatku dochodowym, art. 12).
     * The applicable rate depends on what the taxpayer actually does, which
     * this software cannot infer — the taxpayer selects it in their profile
     * and the selection is recorded in the audit trail.
     */
    'lump_sum_rates_provenance' => [
        'status' => 'secondary',
        'source_document' => 'Ustawa o zryczałtowanym podatku dochodowym od niektórych przychodów osiąganych przez osoby fizyczne, art. 12',
        'source_url' => '',
        'official_source_url' => 'https://isap.sejm.gov.pl/isap.nsf/DocDetails.xsp?id=WDU19981440930',
        'published_on' => null,
        'checked_on' => '2026-09-07',
        'checked_by' => 'claude-code',
        'notes' => 'Lista stawek służy wyłącznie do podpowiedzi w interfejsie. Która stawka ma '
            .'zastosowanie, zależy od faktycznie wykonywanej działalności (PKWiU) i jest decyzją '
            .'podatnika, nie oprogramowania.',
    ],

    'lump_sum_rates' => [
        '0.17' => '17% — wolne zawody',
        '0.15' => '15% — m.in. usługi pośrednictwa, reklamowe, doradcze, kulturalne',
        '0.14' => '14% — usługi w zakresie opieki zdrowotnej, architektoniczne, inżynierskie',
        '0.125' => '12,5% — przychody z najmu ponad 100 000 zł rocznie',
        '0.12' => '12% — m.in. usługi związane z oprogramowaniem (PKWiU ex 62.01.1), doradztwo IT',
        '0.10' => '10% — przychody ze świadczenia usług w zakresie kupna i sprzedaży nieruchomości',
        '0.085' => '8,5% — m.in. działalność usługowa, gastronomia (napoje pow. 1,5% alkoholu), najem do 100 000 zł',
        '0.055' => '5,5% — m.in. działalność wytwórcza, roboty budowlane, przewozy ładunków pow. 2 t',
        '0.03' => '3% — m.in. działalność handlowa (sprzedaż towarów), gastronomia poza wyjątkami',
        '0.02' => '2% — sprzedaż przetworzonych produktów roślinnych i zwierzęcych z własnej uprawy',
    ],

    /**
     * Advance-payment deadlines. A JDG settles monthly by default; the
     * quarterly option exists and is a profile setting.
     */
    'advance_due_day_of_following_month' => 20,
];
