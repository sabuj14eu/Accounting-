<?php

declare(strict_types=1);

namespace Poland\Government;

use DateTimeImmutable;
use Poland\Domain\Money;
use Poland\Interpretation\InterpretationBoundary;
use Poland\Interpretation\Suggestion;

/**
 * Reads a government letter's text and SUGGESTS what it is.
 *
 * Deterministic keyword and pattern matching, not a model — so every conclusion
 * has a stated reason and the same letter always classifies the same way. An AI
 * extractor can be added as another source of suggestions; it would go through
 * the same boundary, and it would still never set a tax figure.
 *
 * When nothing matches, the answer is UNKNOWN. A confident guess about a
 * payment deadline is worse than admitting the letter needs reading.
 */
final class DocumentClassifier
{
    private const AUTHORITIES = [
        GovernmentAuthority::Zus->value => [
            'zakład ubezpieczeń społecznych', 'zaklad ubezpieczen spolecznych',
            'zus ', 'e-składka', 'e-skladka',
        ],
        GovernmentAuthority::UrzadSkarbowy->value => [
            'urząd skarbowy', 'urzad skarbowy', 'naczelnik urzędu skarbowego',
            'mikrorachunek',
        ],
        GovernmentAuthority::Kas->value => [
            'krajowa administracja skarbowa', 'izba administracji skarbowej',
        ],
    ];

    private const PAYMENT = [
        'wezwanie do zapłaty', 'wezwanie do zaplaty', 'zaległość', 'zaleglosc',
        'do zapłaty', 'do zaplaty', 'należność', 'naleznosc', 'upomnienie',
        'tytuł wykonawczy', 'odsetki za zwłokę',
    ];

    private const RESPONSE = [
        'wezwanie do złożenia', 'wezwanie do zlozenia', 'wezwanie do udzielenia',
        'prosimy o wyjaśnienie', 'prosimy o wyjasnienie', 'w terminie 7 dni',
        'w terminie 14 dni', 'czynności sprawdzające', 'czynnosci sprawdzajace',
        'wyjaśnień', 'wyjasnien',
    ];

    private const ISSUE = [
        'niezgodność', 'niezgodnosc', 'rozbieżność', 'rozbieznosc',
        'korekta deklaracji', 'nieprawidłowoś', 'nieprawidlowos',
        'kontrola podatkowa', 'postępowanie podatkowe',
    ];

    private const INFORMATION = [
        'informacja o stanie konta', 'zawiadomienie', 'potwierdzenie',
        'do wiadomości', 'nie wymaga odpowiedzi',
    ];

    public function classify(string $text, string $filename, ?DateTimeImmutable $receivedAt = null): GovernmentDocument
    {
        $haystack = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $suggestions = [];

        $authority = $this->authority($haystack, $suggestions);
        [$action, $actionConfidence, $actionReason] = $this->action($haystack);

        $suggestions['action'] = new Suggestion(
            'action',
            $action->value,
            $actionConfidence,
            $actionReason,
            'DocumentClassifier (deterministic keywords)',
        );

        $caseNumber = $this->caseNumber($haystack, $text, $suggestions);
        $amount = $this->amount($haystack, $suggestions);
        $deadline = $this->deadline($haystack, $suggestions);
        $period = $this->taxPeriod($haystack, $suggestions);
        $issueDate = $this->issueDate($haystack, $suggestions);

        // The boundary, enforced rather than trusted: nothing read from a letter
        // may set a figure the engine owns.
        InterpretationBoundary::assertNoTaxLiability(array_values($suggestions));

        return new GovernmentDocument(
            filename: $filename,
            receivedAt: $receivedAt ?? new DateTimeImmutable(),
            authority: $authority,
            action: $action,
            extracted: $suggestions,
            documentType: $this->documentType($haystack),
            issueDate: $issueDate,
            caseNumber: $caseNumber,
            taxPeriod: $period,
            amount: $amount,
            paymentDeadline: $action === DocumentAction::PaymentRequired ? $deadline : null,
            responseDeadline: $action === DocumentAction::ResponseRequired ? $deadline : null,
            requiredAction: $action->label(),
        );
    }

    /** A document whose text could not be read at all. */
    public function unreadable(string $filename, string $reason, ?DateTimeImmutable $receivedAt = null): GovernmentDocument
    {
        return new GovernmentDocument(
            filename: $filename,
            receivedAt: $receivedAt ?? new DateTimeImmutable(),
            authority: GovernmentAuthority::Unknown,
            action: DocumentAction::Unknown,
            textUnavailable: true,
            extractionNote: $reason,
            requiredAction: 'Otwórz dokument i sklasyfikuj ręcznie.',
        );
    }

    /** @param array<string,Suggestion> $suggestions */
    private function authority(string $text, array &$suggestions): GovernmentAuthority
    {
        foreach (self::AUTHORITIES as $value => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $authority = GovernmentAuthority::from($value);
                    $suggestions['authority'] = new Suggestion(
                        'authority',
                        $authority->value,
                        0.95,
                        sprintf('w treści występuje "%s"', trim($keyword)),
                        'DocumentClassifier',
                        $keyword,
                    );

                    return $authority;
                }
            }
        }

        $suggestions['authority'] = new Suggestion(
            'authority',
            GovernmentAuthority::Unknown->value,
            0.2,
            'nie rozpoznano nadawcy po treści',
            'DocumentClassifier',
        );

        return GovernmentAuthority::Unknown;
    }

    /** @return array{0: DocumentAction, 1: float, 2: string} */
    private function action(string $text): array
    {
        // Order matters: a demand for payment that also asks for an explanation
        // is still first of all a demand for payment.
        foreach ([
            [self::PAYMENT, DocumentAction::PaymentRequired],
            [self::RESPONSE, DocumentAction::ResponseRequired],
            [self::ISSUE, DocumentAction::PossibleIssue],
            [self::INFORMATION, DocumentAction::InformationOnly],
        ] as [$keywords, $action]) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return [$action, 0.9, sprintf('w treści występuje "%s"', $keyword)];
                }
            }
        }

        return [
            DocumentAction::Unknown,
            0.0,
            'nie rozpoznano żadnego znanego sformułowania — wymagany przegląd ręczny',
        ];
    }

    private function documentType(string $text): ?string
    {
        foreach ([
            'wezwanie do zapłaty', 'upomnienie', 'tytuł wykonawczy', 'decyzja',
            'postanowienie', 'zawiadomienie', 'informacja o stanie konta',
            'wezwanie', 'zaświadczenie',
        ] as $type) {
            if (str_contains($text, $type)) {
                return $type;
            }
        }

        return null;
    }

    /** @param array<string,Suggestion> $suggestions */
    private function caseNumber(string $haystack, string $original, array &$suggestions): ?string
    {
        // Polish case references: several slash-separated groups, e.g.
        // 1234-SKA.4103.55.2026.KLM or DFP/2026/08/1234.
        if (preg_match('#\b([A-Z0-9]{2,}(?:[./-][A-Z0-9]{1,}){2,})\b#u', $original, $m) === 1) {
            $suggestions['case_number'] = new Suggestion(
                'case_number',
                $m[1],
                0.7,
                'wzorzec znaku sprawy w treści',
                'DocumentClassifier',
                $m[1],
            );

            return $m[1];
        }

        return null;
    }

    /** @param array<string,Suggestion> $suggestions */
    private function amount(string $text, array &$suggestions): ?Money
    {
        if (preg_match('/(\d[\d\s\x{00A0}]*(?:[,.]\d{2})?)\s*(?:zł|pln)/u', $text, $m) !== 1) {
            return null;
        }

        try {
            $amount = Money::parse($m[1]);
        } catch (\InvalidArgumentException) {
            return null;
        }

        // NOT "vat_due" or "total_due": this is what the LETTER says, an input
        // to compare against the engine, never a replacement for it.
        $suggestions['stated_amount'] = new Suggestion(
            'stated_amount',
            $amount->jsonSerialize(),
            0.8,
            sprintf('kwota "%s" odczytana z treści pisma', trim($m[0])),
            'DocumentClassifier',
            trim($m[0]),
        );

        return $amount;
    }

    /** @param array<string,Suggestion> $suggestions */
    private function deadline(string $text, array &$suggestions): ?DateTimeImmutable
    {
        if (preg_match('/(?:do dnia|termin[a-ząćęłńóśźż]*(?:\s+\w+)?)\s*:?\s*(\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4})/u', $text, $m) === 1) {
            $date = $this->date($m[1]);
            if ($date !== null) {
                $suggestions['deadline'] = new Suggestion(
                    'deadline',
                    $date->format('Y-m-d'),
                    0.85,
                    sprintf('termin odczytany z "%s"', trim($m[0])),
                    'DocumentClassifier',
                    trim($m[0]),
                );

                return $date;
            }
        }

        // "w terminie 14 dni" gives a period, not a date. Reporting it as a date
        // would need the service date, which the letter does not state.
        if (preg_match('/w terminie (\d{1,2}) dni/u', $text, $m) === 1) {
            $suggestions['deadline'] = new Suggestion(
                'deadline_days',
                (int) $m[1],
                0.6,
                sprintf(
                    'pismo podaje termin %d dni, ale nie datę — data zależy od dnia doręczenia, '
                    .'którego pismo nie zawiera',
                    (int) $m[1],
                ),
                'DocumentClassifier',
                trim($m[0]),
            );
        }

        return null;
    }

    /** @param array<string,Suggestion> $suggestions */
    private function taxPeriod(string $text, array &$suggestions): ?string
    {
        if (preg_match('/\b(0[1-9]|1[0-2])[\/.](20\d{2})\b/', $text, $m) === 1) {
            $period = $m[2].'-'.$m[1];
            $suggestions['tax_period'] = new Suggestion(
                'tax_period',
                $period,
                0.75,
                sprintf('okres "%s" w treści', $m[0]),
                'DocumentClassifier',
                $m[0],
            );

            return $period;
        }

        return null;
    }

    /** @param array<string,Suggestion> $suggestions */
    private function issueDate(string $text, array &$suggestions): ?DateTimeImmutable
    {
        if (preg_match('/(?:dnia|z dnia)\s+(\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4})/u', $text, $m) === 1) {
            return $this->date($m[1]);
        }

        return null;
    }

    private function date(string $value): ?DateTimeImmutable
    {
        foreach (['!d.m.Y', '!d-m-Y', '!d/m/Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }
}
