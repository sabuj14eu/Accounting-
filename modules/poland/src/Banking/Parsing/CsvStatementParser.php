<?php

declare(strict_types=1);

namespace Poland\Banking\Parsing;

use DateTimeImmutable;
use Poland\Banking\BankTransaction;
use Poland\Banking\Contracts\StatementParser;
use Poland\Banking\ParsedStatement;
use Poland\Domain\Money;

/**
 * CSV exports from Polish banks.
 *
 * There is no standard: mBank, ING, PKO, Santander and Millennium all differ in
 * delimiter, encoding, column names and whether debits are negative or live in
 * a separate column. So the parser DETECTS rather than assumes, and any row it
 * cannot read becomes a reported problem instead of a silently skipped line —
 * a dropped row understates costs and nothing downstream can notice.
 */
final class CsvStatementParser implements StatementParser
{
    /** @var array<string,list<string>> canonical field => header fragments */
    private const HEADERS = [
        'booking_date' => ['data operacji', 'data ksieg', 'data księg', 'data transakcji', 'data waluty',
                           'data', 'booking date', 'transaction date'],
        'value_date' => ['data waluty', 'value date'],
        'description' => ['opis', 'tytul', 'tytuł', 'szczegoly', 'szczegóły', 'description', 'title'],
        'counterparty' => ['kontrahent', 'nadawca', 'odbiorca', 'nazwa odbiorcy', 'nazwa nadawcy',
                           'strona transakcji', 'counterparty'],
        'account' => ['rachunek odbiorcy', 'rachunek nadawcy', 'numer rachunku', 'konto', 'account'],
        'amount' => ['kwota', 'obciazenia', 'obciążenia', 'uznania', 'amount'],
        'balance' => ['saldo', 'balance'],
        'reference' => ['referencje', 'nr referencyjny', 'reference', 'id transakcji'],
    ];

    public function format(): string
    {
        return 'csv';
    }

    public function supports(string $content, ?string $filename = null): bool
    {
        if ($content === '' || str_starts_with(ltrim($content), '<?xml')) {
            return false;
        }

        // MT940 starts with a tag block, not a delimited header.
        if (preg_match('/^:\d{2}[A-Z]?:/m', $content) === 1) {
            return false;
        }

        return $this->detectDelimiter($content) !== null;
    }

    public function parse(string $content, ?string $filename = null): ParsedStatement
    {
        $content = $this->toUtf8($content);
        $delimiter = $this->detectDelimiter($content) ?? ';';

        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        $problems = [];
        $headerIndex = $this->findHeaderRow($rows);

        if ($headerIndex === null) {
            return new ParsedStatement(
                $this->format(),
                [],
                ['Nie znaleziono wiersza nagłówka — nie wiadomo, która kolumna jest datą, '
                 .'a która kwotą. Plik NIE został zaimportowany.'],
            );
        }

        $map = $this->mapColumns($rows[$headerIndex]);

        foreach (['booking_date', 'amount'] as $required) {
            if (! isset($map[$required])) {
                return new ParsedStatement(
                    $this->format(),
                    [],
                    [sprintf(
                        'W nagłówku brakuje kolumny "%s". Import wstrzymany — zgadywanie kolumn '
                        .'daje wyciąg, który wygląda poprawnie i nim nie jest.',
                        $required,
                    )],
                );
            }
        }

        $transactions = [];
        foreach (array_slice($rows, $headerIndex + 1) as $number => $row) {
            $line = $headerIndex + $number + 2;

            if (count(array_filter($row, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $date = $this->date($this->column($row, $map, 'booking_date'));
            $amount = $this->amount($this->column($row, $map, 'amount'));

            if ($date === null || $amount === null) {
                $problems[] = sprintf(
                    'Wiersz %d pominięty: %s. Treść: %s',
                    $line,
                    $date === null ? 'nieczytelna data' : 'nieczytelna kwota',
                    mb_substr(implode($delimiter, array_map('strval', $row)), 0, 120),
                );

                continue;
            }

            $transactions[] = new BankTransaction(
                bookingDate: $date,
                amount: $amount,
                description: trim((string) ($this->column($row, $map, 'description') ?? '')),
                valueDate: $this->date($this->column($row, $map, 'value_date')),
                counterparty: $this->nullable($this->column($row, $map, 'counterparty')),
                counterpartyAccount: $this->nullable($this->column($row, $map, 'account')),
                reference: $this->nullable($this->column($row, $map, 'reference')),
                balanceAfter: $this->amount($this->column($row, $map, 'balance')),
                raw: ['line' => $line, 'columns' => $row],
            );
        }

        usort(
            $transactions,
            static fn (BankTransaction $a, BankTransaction $b): int
                => $a->bookingDate <=> $b->bookingDate,
        );

        return new ParsedStatement(
            format: $this->format(),
            transactions: $transactions,
            problems: $problems,
            periodFrom: $transactions[0]->bookingDate ?? null,
            periodTo: $transactions === [] ? null : end($transactions)->bookingDate,
        );
    }

    private function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // Polish bank exports are still commonly CP1250 or ISO-8859-2.
        foreach (['Windows-1250', 'ISO-8859-2'] as $encoding) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return $content;
    }

    private function detectDelimiter(string $content): ?string
    {
        $sample = implode("\n", array_slice(preg_split('/\R/', $content) ?: [], 0, 25));
        $best = null;
        $bestCount = 0;

        foreach ([';', ',', "\t", '|'] as $candidate) {
            $count = substr_count($sample, $candidate);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }

        return $bestCount >= 2 ? $best : null;
    }

    /** @param list<list<string|null>> $rows */
    private function findHeaderRow(array $rows): ?int
    {
        foreach (array_slice($rows, 0, 30) as $index => $row) {
            $map = $this->mapColumns($row);
            if (isset($map['booking_date'], $map['amount'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<string|null> $header
     * @return array<string,int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $normalised = mb_strtolower(trim((string) $cell));
            if ($normalised === '') {
                continue;
            }

            foreach (self::HEADERS as $field => $fragments) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ($fragments as $fragment) {
                    if (str_contains($normalised, $fragment)) {
                        $map[$field] = $index;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /** @param list<string|null> $row @param array<string,int> $map */
    private function column(array $row, array $map, string $field): ?string
    {
        if (! isset($map[$field])) {
            return null;
        }

        $value = $row[$map[$field]] ?? null;

        return $value === null ? null : trim((string) $value);
    }

    private function nullable(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd-m-Y', 'd/m/Y', 'Y/m/d', 'Ymd'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }

    private function amount(?string $value): ?Money
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Money::parse($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
