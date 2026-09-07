<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Banking\BankTransaction;
use Poland\Banking\Contracts\StatementParser;
use Poland\Banking\ParsedStatement;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\BankStatementModel;
use Poland\Laravel\Models\BankTransactionModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Models\TransactionClassificationModel;
use Poland\Reconciliation\Classification;
use RuntimeException;

/**
 * Imports bank statements and stores what was read.
 *
 * Duplicate protection works at two levels, because they catch different
 * mistakes: the file checksum catches re-uploading the same export, and the
 * per-transaction fingerprint catches overlapping date ranges in two different
 * exports — which is the common case and the one that would otherwise double a
 * month's costs.
 */
final class BankImportService
{
    /** @param list<StatementParser> $parsers */
    public function __construct(
        private readonly array $parsers,
        private readonly AuditRecorder $audit,
    ) {
    }

    /** @return array{statement: BankStatementModel, parsed: ParsedStatement, imported: int, duplicates: int, possible_duplicates: int} */
    public function import(
        TaxProfileModel $profile,
        string $content,
        string $filename,
        string $importedBy = 'system',
    ): array {
        $parser = $this->parserFor($content, $filename);

        if ($parser === null) {
            throw new RuntimeException(sprintf(
                'Nie rozpoznano formatu pliku "%s". Obsługiwane: CSV, MT940, camt.053 (XML). '
                .'PDF jest przyjmowany, ale nie jest źródłem transakcji.',
                $filename,
            ));
        }

        $parsed = $parser->parse($content, $filename);
        $checksum = hash('sha256', $content);

        $existing = BankStatementModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('checksum', $checksum)
            ->first();

        if ($existing !== null) {
            throw new RuntimeException(sprintf(
                'Ten wyciąg został już zaimportowany %s (%s). Ponowny import podwoiłby koszty miesiąca.',
                $existing->imported_at?->format('d.m.Y H:i') ?? '',
                $existing->filename,
            ));
        }

        return DB::transaction(function () use ($profile, $parsed, $parser, $filename, $checksum, $importedBy): array {
            $statement = BankStatementModel::create([
                'tax_profile_id' => $profile->getKey(),
                'filename' => $filename,
                'format' => $parser->format(),
                'account_number' => $parsed->accountNumber,
                'period_from' => $parsed->periodFrom?->format('Y-m-d'),
                'period_to' => $parsed->periodTo?->format('Y-m-d'),
                'opening_balance' => $parsed->openingBalance?->jsonSerialize(),
                'closing_balance' => $parsed->closingBalance?->jsonSerialize(),
                'balances_reconcile' => $parsed->balancesReconcile(),
                'problems' => $parsed->problems,
                'transaction_count' => $parsed->count(),
                'checksum' => $checksum,
                'imported_at' => now(),
                'imported_by' => $importedBy,
            ]);

            $imported = 0;
            $duplicates = 0;
            $possibleDuplicates = 0;

            foreach ($parsed->transactions as $transaction) {
                $fingerprint = $transaction->fingerprint();

                $already = BankTransactionModel::query()
                    ->where('tax_profile_id', $profile->getKey())
                    ->where('fingerprint', $fingerprint)
                    ->exists();

                if ($already) {
                    $duplicates++;

                    continue;
                }

                // The same transaction can arrive in two formats with different
                // optional fields, so the exact fingerprint misses it. Flag the
                // second copy rather than importing it silently (doubles costs)
                // or discarding it (loses a genuine second payment).
                $twin = BankTransactionModel::query()
                    ->where('tax_profile_id', $profile->getKey())
                    // whereDate, not where: the column round-trips as
                    // "2026-08-14 00:00:00" and an equality against "2026-08-14"
                    // silently never matches.
                    ->whereDate('booking_date', $transaction->bookingDate->format('Y-m-d'))
                    // Compare in grosze. A decimal column round-trips as
                    // "-5970" on one driver and "-5970.00" on another, and a
                    // string comparison silently never matches.
                    ->whereRaw('CAST(ROUND(amount * 100) AS INTEGER) = ?', [$transaction->amount->grosze])
                    ->where('statement_id', '!=', $statement->getKey())
                    ->whereNull('possible_duplicate_of')
                    ->first();

                BankTransactionModel::create([
                    'tax_profile_id' => $profile->getKey(),
                    'statement_id' => $statement->getKey(),
                    'possible_duplicate_of' => $twin?->getKey(),
                    'duplicate_reason' => $twin === null ? null : sprintf(
                        'Ta sama data (%s) i kwota (%s) co transakcja #%d z wyciągu "%s". '
                        .'Możliwy ten sam przelew zaimportowany z dwóch plików — '
                        .'NIE zaksięgowano automatycznie.',
                        $transaction->bookingDate->format('d.m.Y'),
                        $transaction->amount->format(),
                        $twin->getKey(),
                        $twin->statement?->filename ?? 'nieznany',
                    ),
                    'duplicate_decision' => $twin === null ? null : 'pending',
                    'booking_date' => $transaction->bookingDate->format('Y-m-d'),
                    'value_date' => $transaction->valueDate?->format('Y-m-d'),
                    'amount' => $transaction->amount->jsonSerialize(),
                    'direction' => $transaction->direction(),
                    'description' => $transaction->description,
                    'counterparty' => $transaction->counterparty,
                    'counterparty_account' => $transaction->counterpartyAccount,
                    'reference' => $transaction->reference,
                    'balance_after' => $transaction->balanceAfter?->jsonSerialize(),
                    'currency' => $transaction->currency ?? 'PLN',
                    'fingerprint' => $fingerprint,
                    'period' => $transaction->bookingDate->format('Y-m'),
                    'raw' => $transaction->raw,
                ]);

                $imported++;
                if ($twin !== null) {
                    $possibleDuplicates++;
                }
            }

            $this->audit->record(
                AuditRecorder::STATEMENT_IMPORTED,
                (int) $profile->getKey(),
                $statement,
                null,
                null,
                [
                    'format' => $parser->format(),
                    'imported' => $imported,
                    'duplicates' => $duplicates,
                    'possible_duplicates' => $possibleDuplicates,
                    'balances_reconcile' => $parsed->balancesReconcile(),
                    'problems' => count($parsed->problems),
                ],
                $parsed->isTrustworthy() ? 'ok' : 'partial',
                $parsed->problems === [] ? null : implode('; ', $parsed->problems),
                'bank-import',
            );

            return [
                'statement' => $statement,
                'parsed' => $parsed,
                'imported' => $imported,
                'duplicates' => $duplicates,
                'possible_duplicates' => $possibleDuplicates,
            ];
        });
    }

    /** @return list<BankTransaction> */
    public function transactionsFor(TaxProfileModel $profile, Period $period): array
    {
        return BankTransactionModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            // An unresolved possible duplicate is not a second payment until
            // somebody says it is. Counting it would double the month.
            ->where(function ($q): void {
                $q->whereNull('possible_duplicate_of')
                    ->orWhere('duplicate_decision', 'confirmed_distinct');
            })
            ->orderBy('booking_date')
            ->get()
            ->map(fn (BankTransactionModel $row): BankTransaction => $row->toDomain())
            ->all();
    }

    /** Transactions flagged as possibly the same payment imported twice. */
    public function possibleDuplicates(TaxProfileModel $profile, Period $period): int
    {
        return BankTransactionModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->whereNotNull('possible_duplicate_of')
            ->where('duplicate_decision', 'pending')
            ->count();
    }

    /** A person deciding whether a flagged row is a real second payment. */
    public function resolveDuplicate(
        TaxProfileModel $profile,
        int $transactionId,
        bool $isDistinct,
        string $decidedBy,
    ): BankTransactionModel {
        $transaction = BankTransactionModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->findOrFail($transactionId);

        $transaction->forceFill([
            'duplicate_decision' => $isDistinct ? 'confirmed_distinct' : 'confirmed_duplicate',
        ])->save();

        $this->audit->record(
            AuditRecorder::STATEMENT_IMPORTED,
            (int) $profile->getKey(),
            $transaction,
            $transaction->period,
            null,
            [
                'duplicate_decision' => $transaction->duplicate_decision,
                'decided_by' => $decidedBy,
            ],
        );

        return $transaction;
    }

    public function untrustworthyStatements(TaxProfileModel $profile, Period $period): int
    {
        return BankStatementModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where(function ($q) use ($period): void {
                $q->whereBetween('period_from', [$period->firstDay(), $period->lastDay()])
                    ->orWhereBetween('period_to', [$period->firstDay(), $period->lastDay()]);
            })
            ->where(function ($q): void {
                $q->where('balances_reconcile', false)
                    ->orWhereRaw("problems IS NOT NULL AND problems <> '[]'");
            })
            ->count();
    }

    /** @param list<Classification> $classifications */
    public function storeClassifications(TaxProfileModel $profile, array $classifications): void
    {
        foreach ($classifications as $classification) {
            $transaction = BankTransactionModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('fingerprint', $classification->transaction->fingerprint())
                ->first();

            if ($transaction === null) {
                continue;
            }

            $existing = TransactionClassificationModel::query()
                ->where('transaction_id', $transaction->getKey())
                ->first();

            // A decision a person already made is never overwritten by a rerun.
            if ($existing !== null && $existing->decision !== 'pending') {
                continue;
            }

            TransactionClassificationModel::updateOrCreate(
                ['transaction_id' => $transaction->getKey()],
                [
                    'category' => $classification->category->value,
                    'confidence' => $classification->confidence,
                    'reason' => $classification->reason(),
                    'match_quality' => $classification->quality->value,
                    'matched_document' => $classification->matchedDocument,
                    'matched_document_type' => $classification->matchedDocumentType,
                    'auto_bookable' => $classification->isAutoBookable(),
                    'decision' => 'pending',
                ],
            );
        }
    }

    /** Record a human accepting or rejecting a suggested match. */
    public function decide(
        TaxProfileModel $profile,
        int $transactionId,
        bool $accepted,
        string $decidedBy,
        ?string $note = null,
    ): TransactionClassificationModel {
        $classification = TransactionClassificationModel::query()
            ->where('transaction_id', $transactionId)
            ->firstOrFail();

        $old = ['decision' => $classification->decision, 'category' => $classification->category];

        $classification->forceFill([
            'decision' => $accepted ? 'accepted' : 'rejected',
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->record(
            AuditRecorder::MATCH_DECIDED,
            (int) $profile->getKey(),
            $classification,
            null,
            $old,
            [
                'decision' => $accepted ? 'accepted' : 'rejected',
                'matched_document' => $classification->matched_document,
                'note' => $note,
            ],
        );

        return $classification;
    }

    private function parserFor(string $content, string $filename): ?StatementParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($content, $filename)) {
                return $parser;
            }
        }

        return null;
    }
}
