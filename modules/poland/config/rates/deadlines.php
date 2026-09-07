<?php

declare(strict_types=1);

/**
 * Statutory payment/filing deadlines, expressed as a day of the month
 * FOLLOWING the settlement period.
 *
 * A deadline landing on a Saturday, Sunday or public holiday moves to the next
 * working day (Ordynacja podatkowa art. 12 § 5; kodeks cywilny art. 115 for
 * ZUS). That shift is applied by the calendar service, not stored here.
 */

return [
    'zus' => [
        // A sole trader paying only their own contributions: the 20th.
        // Payers with employees settle by the 15th, and budget units by the 5th
        // — configured per company, never inferred.
        'day' => 20,
        'label' => 'Składki ZUS (DRA / opłata za miesiąc)',
    ],
    'pit_advance' => [
        'day' => 20,
        'label' => 'Zaliczka na podatek dochodowy (PIT)',
    ],
    'vat' => [
        'day' => 25,
        'label' => 'VAT — zapłata i wysyłka JPK_V7',
    ],

    // Public holidays in Poland. Used only to move a deadline forward, never to
    // move it earlier. Missing years mean the shift cannot be computed, and the
    // report says so instead of silently returning the raw statutory date.
    'public_holidays' => [
        2025 => [
            '01-01', '01-06', '04-20', '04-21', '05-01', '05-03', '06-08',
            '06-19', '08-15', '11-01', '11-11', '12-25', '12-26',
        ],
        2026 => [
            '01-01', '01-06', '04-05', '04-06', '05-01', '05-03', '05-24',
            '06-04', '08-15', '11-01', '11-11', '12-25', '12-26',
        ],
        2027 => [
            '01-01', '01-06', '03-28', '03-29', '05-01', '05-03', '05-16',
            '05-27', '08-15', '11-01', '11-11', '12-25', '12-26',
        ],
    ],
];
