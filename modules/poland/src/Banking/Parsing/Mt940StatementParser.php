<?php

declare(strict_types=1);

namespace Poland\Banking\Parsing;

use DateTimeImmutable;
use Poland\Banking\BankTransaction;
use Poland\Banking\Contracts\StatementParser;
use Poland\Banking\ParsedStatement;
use Poland\Domain\Money;

/**
 * SWIFT MT940 statements.
 *
 * Tag-based and well specified, so unlike CSV this can be parsed exactly:
 *   :20:  reference        :25:  account
 *   :60F: opening balance  :62F: closing balance
 *   :61:  transaction      :86:  its description (may be several lines)
 *
 * The opening and closing balances are read so the import can PROVE it did not
 * drop a transaction, which is the failure mode that quietly understates costs.
 */
final class Mt940StatementParser implements StatementParser
{
    public function format(): string
    {
        return 'mt940';
    }

    public function supports(string $content, ?string $filename = null): bool
    {
        return preg_match('/^:(20|25|60F):/m', $content) === 1
            && preg_match('/^:61:/m', $content) === 1;
    }

    public function parse(string $content, ?string $filename = null): ParsedStatement
    {
        $lines = preg_split('/\R/', trim($content)) ?: [];

        $problems = [];
        $transactions = [];
        $account = null;
        $opening = null;
        $closing = null;
        $periodFrom = null;
        $periodTo = null;

        /** @var array<string,mixed>|null $pending */
        $pending = null;

        $flush = function () use (&$pending, &$transactions): void {
            if ($pending === null) {
                return;
            }

            $transactions[] = new BankTransaction(
                bookingDate: $pending['booking'],
                amount: $pending['amount'],
                description: trim($pending['description']),
                valueDate: $pending['value'],
                counterparty: $pending['counterparty'],
                counterpartyAccount: $pending['account'],
                reference: $pending['reference'],
                raw: ['tag61' => $pending['raw']],
            );

            $pending = null;
        };

        foreach ($lines as $number => $line) {
            $line = rtrim($line, "\r");

            if (preg_match('/^:25:(.+)$/', $line, $m) === 1) {
                $flush();
                $account = trim($m[1]);

                continue;
            }

            if (preg_match('/^:60[FM]:([CD])(\d{6})([A-Z]{3})([\d,\.]+)$/', $line, $m) === 1) {
                $flush();
                $opening = $this->signed($m[1], $m[4]);
                $periodFrom = $this->date($m[2]);

                continue;
            }

            if (preg_match('/^:62[FM]:([CD])(\d{6})([A-Z]{3})([\d,\.]+)$/', $line, $m) === 1) {
                $flush();
                $closing = $this->signed($m[1], $m[4]);
                $periodTo = $this->date($m[2]);

                continue;
            }

            // :61:YYMMDD[MMDD]{C|D|RC|RD}[funds]amount... 
            if (preg_match('/^:61:(\d{6})(\d{4})?(R?[CD])([A-Z])?([\d,\.]+)(.*)$/', $line, $m) === 1) {
                $flush();

                $booking = $this->date($m[1]);
                if ($booking === null) {
                    $problems[] = sprintf('Linia %d: nieczytelna data w tagu :61:', $number + 1);

                    continue;
                }

                $amount = $this->signed($m[3], $m[5]);
                if ($amount === null) {
                    $problems[] = sprintf('Linia %d: nieczytelna kwota w tagu :61:', $number + 1);

                    continue;
                }

                $pending = [
                    'booking' => $this->valueDateFrom($m[1], $m[2] ?? null) ?? $booking,
                    'value' => $booking,
                    'amount' => $amount,
                    'description' => '',
                    'counterparty' => null,
                    'account' => null,
                    'reference' => $this->reference($m[6] ?? ''),
                    'raw' => $line,
                ];

                continue;
            }

            if (preg_match('/^:86:(.*)$/', $line, $m) === 1) {
                if ($pending !== null) {
                    $this->applyStructuredFields($pending, $m[1]);
                    $pending['description'] = $this->readableDescription($m[1]);
                }

                continue;
            }

            // Continuation of the previous :86: block.
            if ($pending !== null && $line !== '' && ! str_starts_with($line, ':') && $line !== '-') {
                $this->applyStructuredFields($pending, $line);
                $pending['description'] = trim(
                    $pending['description'].' '.$this->readableDescription($line),
                );
            }
        }

        $flush();

        return new ParsedStatement(
            format: $this->format(),
            transactions: $transactions,
            problems: $problems,
            accountNumber: $account,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            openingBalance: $opening,
            closingBalance: $closing,
        );
    }

    /**
     * Turn a :86: block into something a person can read.
     *
     * Polish banks pack subfields in as `~20`..`~63` codes. Leaving them in the
     * description makes every matcher search through markup and every screen
     * show it, so the codes are stripped once, here, after the structured
     * fields have been taken out of them.
     */
    private function readableDescription(string $text): string
    {
        // Drop a leading numeric business-transaction code ("023", "020").
        $text = preg_replace('/^\d{3}(?=~|\s|$)/', '', trim($text)) ?? $text;

        // Keep the free-text subfields (~20-~29 carry the payment title); the
        // rest are names and accounts already extracted into their own fields.
        if (preg_match_all('/~2\d([^~]*)/', $text, $matches) === 1 || ($matches[1] ?? []) !== []) {
            $title = trim(implode(' ', array_map('trim', $matches[1])));
            if ($title !== '') {
                return preg_replace('/\s+/u', ' ', $title) ?? $title;
            }
        }

        // No subfield markup at all: it is already plain text.
        $plain = preg_replace('/~\d{2}/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    }

    /** @param array<string,mixed> $pending */
    private function applyStructuredFields(array &$pending, string $text): void
    {
        // Polish banks put subfields in :86: as ~20..~29 (title), ~32/~33
        // (counterparty name), ~38 (IBAN).
        if (preg_match_all('/~3[23]([^~]+)/', $text, $m) === 1 || ($m[1] ?? []) !== []) {
            $name = trim(implode(' ', $m[1] ?? []));
            if ($name !== '') {
                $pending['counterparty'] = $pending['counterparty'] ?? $name;
            }
        }

        if (preg_match('/~38([A-Z]{2}\d{2}[A-Z0-9]+)/', $text, $m) === 1) {
            $pending['account'] = $pending['account'] ?? $m[1];
        } elseif (preg_match('/\b([A-Z]{2}\d{24,26})\b/', $text, $m) === 1) {
            $pending['account'] = $pending['account'] ?? $m[1];
        } elseif (preg_match('/\b(\d{26})\b/', $text, $m) === 1) {
            $pending['account'] = $pending['account'] ?? $m[1];
        }
    }

    private function reference(string $tail): ?string
    {
        if (preg_match('/\/\/(\S+)/', $tail, $m) === 1) {
            return $m[1];
        }

        $trimmed = trim($tail);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 64);
    }

    private function date(string $yymmdd): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!ymd', $yymmdd);

        return $parsed === false ? null : $parsed;
    }

    private function valueDateFrom(string $yymmdd, ?string $mmdd): ?DateTimeImmutable
    {
        if ($mmdd === null || $mmdd === '') {
            return null;
        }

        // The entry date carries no year; it is the statement year, except
        // across a December/January boundary.
        $year = substr($yymmdd, 0, 2);
        $parsed = DateTimeImmutable::createFromFormat('!ymd', $year.$mmdd);
        if ($parsed === false) {
            return null;
        }

        $value = $this->date($yymmdd);
        if ($value !== null && $parsed->diff($value)->days > 300) {
            $parsed = $parsed->modify($parsed < $value ? '+1 year' : '-1 year');
        }

        return $parsed;
    }

    private function signed(string $marker, string $amount): ?Money
    {
        $normalised = str_replace(',', '.', $amount);
        if (! is_numeric($normalised)) {
            return null;
        }

        $money = Money::parse($normalised);
        $isDebit = str_starts_with($marker, 'D') || $marker === 'RC';

        return $isDebit ? Money::zero()->minus($money) : $money;
    }
}
