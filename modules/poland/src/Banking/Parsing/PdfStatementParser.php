<?php

declare(strict_types=1);

namespace Poland\Banking\Parsing;

use Poland\Banking\Contracts\StatementParser;
use Poland\Banking\ParsedStatement;

/**
 * PDF statements.
 *
 * Deliberately NOT implemented as a silent best-effort. A PDF statement has no
 * machine-readable structure: extracting transactions means text extraction plus
 * column inference, and a column misread by one position turns a 1 234,56 debit
 * into a 123 456 credit that balances against nothing and gets booked.
 *
 * So this parser refuses, names what is missing, and points at the formats that
 * can be read exactly. When a text-extraction port is configured, an
 * implementation can replace this one — and it must still prove itself against
 * the statement's own opening and closing balances before its output is booked.
 */
final class PdfStatementParser implements StatementParser
{
    public function format(): string
    {
        return 'pdf';
    }

    public function supports(string $content, ?string $filename = null): bool
    {
        return str_starts_with($content, '%PDF-')
            || ($filename !== null && str_ends_with(strtolower($filename), '.pdf'));
    }

    public function parse(string $content, ?string $filename = null): ParsedStatement
    {
        return new ParsedStatement(
            format: $this->format(),
            transactions: [],
            problems: [
                'Wyciąg w formacie PDF nie został zaimportowany. PDF nie ma struktury '
                .'maszynowej — odczyt wymagałby zgadywania kolumn, a jedna źle odczytana '
                .'kolumna zamienia obciążenie w uznanie i trafia do księgi.',
                'Pobierz z bankowości ten sam wyciąg jako CSV, MT940 lub XML (camt.053) — '
                .'te formaty są odczytywane dokładnie i sprawdzane saldem otwarcia i zamknięcia.',
                'Plik został zachowany i można go dołączyć do dokumentacji, ale nie stanowi '
                .'źródła transakcji.',
            ],
        );
    }
}
