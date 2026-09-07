<?php

declare(strict_types=1);

namespace Poland\Banking\Parsing;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Poland\Banking\BankTransaction;
use Poland\Banking\Contracts\StatementParser;
use Poland\Banking\ParsedStatement;
use Poland\Domain\Money;

/**
 * ISO 20022 camt.053 statements.
 *
 * Read by local element name, ignoring namespaces, for the same reason the FA
 * parser is: camt.053.001.02 and .08 differ only in namespace URI for the
 * elements that matter here, and a parser pinned to one returns silently empty
 * for the other.
 *
 * The sign comes from CdtDbtInd, never from the amount, because camt amounts
 * are always positive magnitudes.
 */
final class Camt053StatementParser implements StatementParser
{
    public function format(): string
    {
        return 'camt053';
    }

    public function supports(string $content, ?string $filename = null): bool
    {
        return str_contains($content, '<')
            && (str_contains($content, 'camt.053') || preg_match('/<[\w:]*BkToCstmrStmt\b/', $content) === 1);
    }

    public function parse(string $content, ?string $filename = null): ParsedStatement
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xmlErrors = array_map(
            static fn (\LibXMLError $e): string => trim($e->message),
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return new ParsedStatement($this->format(), [], [
                'Nie udało się odczytać XML: '.($xmlErrors === [] ? 'nieznany błąd' : implode('; ', $xmlErrors)),
            ]);
        }

        $xpath = new DOMXPath($document);
        $problems = [];
        $transactions = [];

        $account = $this->text($xpath, '//*[local-name()="Stmt"]/*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]')
            ?? $this->text($xpath, '//*[local-name()="Acct"]//*[local-name()="Othr"]/*[local-name()="Id"]');

        [$opening, $openingDate] = $this->balance($xpath, ['OPBD', 'PRCD']);
        [$closing, $closingDate] = $this->balance($xpath, ['CLBD', 'CLAV']);

        $entries = $xpath->query('//*[local-name()="Ntry"]');
        foreach ($entries ?? [] as $index => $entry) {
            $indicator = $this->text($xpath, './*[local-name()="CdtDbtInd"]', $entry);
            $rawAmount = $this->text($xpath, './*[local-name()="Amt"]', $entry);
            $bookingDate = $this->date(
                $this->text($xpath, './*[local-name()="BookgDt"]/*[local-name()="Dt"]', $entry)
                ?? $this->text($xpath, './*[local-name()="BookgDt"]/*[local-name()="DtTm"]', $entry),
            );

            if ($indicator === null || $rawAmount === null || $bookingDate === null) {
                $problems[] = sprintf(
                    'Pozycja %d pominięta: brak %s.',
                    $index + 1,
                    $indicator === null ? 'CdtDbtInd' : ($rawAmount === null ? 'Amt' : 'BookgDt'),
                );

                continue;
            }

            try {
                $magnitude = Money::parse($rawAmount);
            } catch (\InvalidArgumentException) {
                $problems[] = sprintf('Pozycja %d pominięta: nieczytelna kwota "%s".', $index + 1, $rawAmount);

                continue;
            }

            // camt always states a positive magnitude; direction is the indicator.
            $amount = $indicator === 'DBIT' ? Money::zero()->minus($magnitude) : $magnitude;

            $description = $this->text($xpath, './/*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $entry)
                ?? $this->text($xpath, './*[local-name()="AddtlNtryInf"]', $entry)
                ?? '';

            $counterparty = $indicator === 'DBIT'
                ? $this->text($xpath, './/*[local-name()="Cdtr"]/*[local-name()="Nm"]', $entry)
                : $this->text($xpath, './/*[local-name()="Dbtr"]/*[local-name()="Nm"]', $entry);

            $counterAccount = $indicator === 'DBIT'
                ? $this->text($xpath, './/*[local-name()="CdtrAcct"]//*[local-name()="IBAN"]', $entry)
                : $this->text($xpath, './/*[local-name()="DbtrAcct"]//*[local-name()="IBAN"]', $entry);

            $transactions[] = new BankTransaction(
                bookingDate: $bookingDate,
                amount: $amount,
                description: trim($description),
                valueDate: $this->date($this->text($xpath, './*[local-name()="ValDt"]/*[local-name()="Dt"]', $entry)),
                counterparty: $counterparty,
                counterpartyAccount: $counterAccount,
                reference: $this->text($xpath, './/*[local-name()="EndToEndId"]', $entry)
                    ?? $this->text($xpath, './*[local-name()="AcctSvcrRef"]', $entry),
                currency: $this->attribute($xpath, './*[local-name()="Amt"]', 'Ccy', $entry) ?? 'PLN',
                raw: ['index' => $index],
            );
        }

        return new ParsedStatement(
            format: $this->format(),
            transactions: $transactions,
            problems: $problems,
            accountNumber: $account,
            periodFrom: $openingDate,
            periodTo: $closingDate,
            openingBalance: $opening,
            closingBalance: $closing,
        );
    }

    /**
     * @param list<string> $codes
     * @return array{0: Money|null, 1: DateTimeImmutable|null}
     */
    private function balance(DOMXPath $xpath, array $codes): array
    {
        foreach ($codes as $code) {
            $node = $xpath->query(sprintf(
                '//*[local-name()="Bal"][.//*[local-name()="Cd"]="%s"]',
                $code,
            ))?->item(0);

            if ($node === null) {
                continue;
            }

            $amount = $this->text($xpath, './*[local-name()="Amt"]', $node);
            $indicator = $this->text($xpath, './*[local-name()="CdtDbtInd"]', $node);
            $date = $this->date($this->text($xpath, './*[local-name()="Dt"]/*[local-name()="Dt"]', $node));

            if ($amount === null) {
                continue;
            }

            try {
                $money = Money::parse($amount);
            } catch (\InvalidArgumentException) {
                continue;
            }

            return [$indicator === 'DBIT' ? Money::zero()->minus($money) : $money, $date];
        }

        return [null, null];
    }

    private function text(DOMXPath $xpath, string $expression, ?\DOMNode $context = null): ?string
    {
        $nodes = $context === null ? $xpath->query($expression) : $xpath->query($expression, $context);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    private function attribute(DOMXPath $xpath, string $expression, string $name, ?\DOMNode $context = null): ?string
    {
        $nodes = $context === null ? $xpath->query($expression) : $xpath->query($expression, $context);
        $node = $nodes === false ? null : $nodes->item(0);
        if (! $node instanceof \DOMElement) {
            return null;
        }

        $value = $node->getAttribute($name);

        return $value === '' ? null : $value;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        foreach (['!Y-m-d', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', DATE_ATOM] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return null;
    }
}
