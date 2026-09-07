<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use DateTimeImmutable;
use Poland\Banking\BankTransaction;
use Poland\Certainty\Caveat;
use Poland\Certainty\CertaintyReport;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\GovernmentDocumentModel;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Reconciliation\MatchCandidate;
use Poland\Reconciliation\MatchQuality;
use Poland\Reconciliation\TransactionCategory;
use Poland\Reconciliation\TransactionMatcher;
use Poland\Reporting\AccountantReport;
use Throwable;

/**
 * The monthly close, in the order the steps depend on each other.
 *
 * Every step reports what it could NOT do, and those become caveats on the
 * report. A step failing does not abort the close — a month with no KSeF
 * connection still needs its ZUS figure — but it does downgrade the month's
 * certainty, and the report says which step and why.
 *
 * The governing rule: never make the result look more certain than the data
 * behind it.
 */
final class MonthCloseService
{
    public function __construct(
        private readonly KsefIngestService $ksef,
        private readonly BankImportService $bank,
        private readonly MonthlyReportService $reports,
        private readonly TransactionMatcher $matcher,
        private readonly AuditRecorder $audit,
    ) {
    }

    /**
     * @return array{report: AccountantReport|null, certainty: CertaintyReport, steps: list<array<string,mixed>>}
     */
    public function close(TaxProfileModel $profile, Period $period, bool $pullKsef = true): array
    {
        $steps = [];
        $caveats = [];

        // 1. KSeF ------------------------------------------------------------
        $steps[] = $this->step('ksef', function () use ($profile, $period, $pullKsef, &$caveats): string {
            if (! $pullKsef) {
                return 'pominięto na żądanie';
            }

            $reason = $this->ksef->unavailableReason($profile);
            if ($reason !== null) {
                $caveats[] = Caveat::ksefUnavailable($reason);

                return 'niedostępne: '.$reason;
            }

            $result = $this->ksef->sync(
                $profile,
                $period->firstDay(),
                $period->lastDay()->setTime(23, 59, 59),
            );

            if (! $result->isClean()) {
                $caveats[] = Caveat::ksefUnavailable($result->stoppedBecause ?? 'część faktur nie została pobrana');
            }

            return $result->summary();
        });

        // 2. Bank statements --------------------------------------------------
        $transactions = $this->bank->transactionsFor($profile, $period);
        $steps[] = $this->step('bank', function () use ($transactions, $profile, $period, &$caveats): string {
            if ($transactions === []) {
                $caveats[] = Caveat::bankStatementMissing($period->label());

                return 'brak wyciągu za ten okres';
            }

            $untrustworthy = $this->bank->untrustworthyStatements($profile, $period);
            if ($untrustworthy > 0) {
                $caveats[] = Caveat::bankStatementDidNotBalance($untrustworthy);
            }

            // Having transactions is not the same as having the whole month.
            $coverage = $this->bank->coverage($profile, $period);
            if ($coverage['status'] !== 'COMPLETE') {
                $caveats[] = Caveat::bankStatementIncomplete(
                    $period->label(),
                    $coverage['covered'] ?? 'zakres nieustalony',
                );
            }

            $duplicates = $this->bank->possibleDuplicates($profile, $period);
            if ($duplicates > 0) {
                $caveats[] = new Caveat(
                    'possible_duplicate_transactions',
                    \Poland\Certainty\DataCertainty::RequiresReview,
                    sprintf(
                        '%d transakcji wygląda na ten sam przelew zaimportowany z dwóch plików. '
                        .'Nie zostały wliczone — rozstrzygnij, czy to jedna płatność czy dwie.',
                        $duplicates,
                    ),
                    'Otwórz listę transakcji i rozstrzygnij oznaczone duplikaty.',
                );
            }

            return sprintf(
                '%d transakcji, pokrycie %s%s',
                count($transactions),
                $coverage['status'],
                $duplicates > 0 ? sprintf(', %d możliwych duplikatów wyłączonych', $duplicates) : '',
            );
        });

        // 3. Government documents ---------------------------------------------
        $steps[] = $this->step('government', function () use ($profile, $period, &$caveats): string {
            $pending = GovernmentDocumentModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('status', 'new')
                ->where(function ($q) use ($period): void {
                    $q->whereNull('tax_period')->orWhere('tax_period', $period->toString());
                })
                ->get();

            $unclear = $pending->filter(fn ($d): bool => (bool) $d->needs_manual_review);

            foreach ($unclear as $document) {
                $caveats[] = Caveat::interpretationInconclusive($document->filename);
            }

            return sprintf('%d nowych pism, %d wymaga przeglądu', $pending->count(), $unclear->count());
        });

        // 4. Reconciliation ----------------------------------------------------
        $steps[] = $this->step('reconciliation', function () use ($profile, $period, $transactions, &$caveats): string {
            if ($transactions === []) {
                return 'brak transakcji do uzgodnienia';
            }

            $classifications = $this->matcher->classifyAll(
                $transactions,
                $this->candidates($profile, $period),
            );

            $this->bank->storeClassifications($profile, $classifications);

            $needsReview = array_filter(
                $classifications,
                static fn ($c): bool => $c->category === TransactionCategory::NeedsReview
                    || $c->quality === MatchQuality::Possible,
            );

            if ($needsReview !== []) {
                $caveats[] = Caveat::unmatchedTransactions(count($needsReview));
            }

            return sprintf(
                '%d dopasowanych, %d do przeglądu',
                count($classifications) - count($needsReview),
                count($needsReview),
            );
        });

        // 5-8. Recalculate and generate ---------------------------------------
        $report = null;
        $steps[] = $this->step('report', function () use ($profile, $period, &$report, &$caveats): string {
            try {
                $report = $this->reports->generate($profile, $period, 'month-close');
            } catch (\Poland\Rates\UnverifiedRateException $e) {
                $caveats[] = Caveat::ratesUnverified(implode(', ', array_keys($e->offending)));

                return 'zablokowane: stawki niezweryfikowane';
            }

            if (! $report->report->ratesFitForFiling) {
                $caveats[] = Caveat::ratesUnverified(implode(', ', array_keys($report->report->rateProvenance)));
            }

            return sprintf(
                'wersja %d, do zapłaty %s',
                $report->version ?? 0,
                $report->totalOutstanding()->format(),
            );
        });

        // 9-10. Unresolved items and certainty ---------------------------------
        $certainty = CertaintyReport::of(
            $caveats,
            // "Verified" requires an independent source to have confirmed the
            // figures — here, a bank statement that balanced and reconciled.
            reconciled: $transactions !== [] && $caveats === [],
        );

        $this->audit->record(
            AuditRecorder::PERIOD_CLOSED,
            (int) $profile->getKey(),
            null,
            $period->toString(),
            null,
            [
                'certainty' => $certainty->certainty->value,
                'caveats' => array_map(static fn (Caveat $c): string => $c->code, $caveats),
                'steps' => $steps,
            ],
            $certainty->isFinal() ? 'ok' : 'partial',
            null,
            'month-close',
        );

        return ['report' => $report, 'certainty' => $certainty, 'steps' => $steps];
    }

    /**
     * Documents a bank transaction might be paying.
     *
     * @return list<MatchCandidate>
     */
    private function candidates(TaxProfileModel $profile, Period $period): array
    {
        $candidates = [];

        // KSeF invoices for the month and the one before it: an August invoice
        // is commonly paid in September and the reverse happens too.
        KsefDocumentModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->whereIn('period', [$period->toString(), $period->previous()->toString()])
            ->get()
            ->each(function (KsefDocumentModel $invoice) use (&$candidates): void {
                $gross = $invoice->grossMoney();
                if ($gross === null || $gross->isZero()) {
                    return;
                }

                $outgoing = $invoice->direction === KsefDocumentModel::DIRECTION_OUTGOING;

                $candidates[] = new MatchCandidate(
                    reference: $invoice->invoice_number ?? $invoice->ksef_number,
                    type: 'ksef_invoice',
                    amount: $gross->isNegative() ? Money::zero()->minus($gross) : $gross,
                    date: $invoice->invoice_date !== null
                        ? new DateTimeImmutable($invoice->invoice_date->format('Y-m-d'))
                        : null,
                    counterpartyName: $outgoing ? $invoice->buyer_name : $invoice->seller_name,
                    counterpartyNip: $outgoing ? $invoice->buyer_nip : $invoice->seller_nip,
                    references: array_filter([$invoice->invoice_number, $invoice->ksef_number]),
                    category: TransactionCategory::KsefInvoicePayment,
                    incoming: $outgoing,
                );
            });

        // The month's own payment obligations, so a ZUS or tax transfer matches
        // the amount the engine computed rather than only a keyword.
        \Poland\Laravel\Models\PaymentObligationModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->whereIn('period', [$period->toString(), $period->previous()->toString()])
            ->get()
            ->each(function ($obligation) use (&$candidates): void {
                $amount = $obligation->amountMoney();
                if ($amount->isZero()) {
                    return;
                }

                $candidates[] = new MatchCandidate(
                    reference: sprintf('%s %s', strtoupper($obligation->kind), $obligation->period),
                    type: 'payment_obligation',
                    amount: $amount,
                    date: $obligation->due_date !== null
                        ? new DateTimeImmutable($obligation->due_date->format('Y-m-d'))
                        : null,
                    counterpartyName: $obligation->pay_to,
                    references: [$obligation->period, strtoupper($obligation->kind)],
                    category: $obligation->kind === 'zus'
                        ? TransactionCategory::ZusPayment
                        : TransactionCategory::TaxPayment,
                    incoming: false,
                );
            });

        return $candidates;
    }

    /** @return array<string,mixed> */
    private function step(string $name, callable $work): array
    {
        $started = microtime(true);

        try {
            $outcome = (string) $work();
            $status = 'ok';
            $error = null;
        } catch (Throwable $e) {
            // A failing step downgrades certainty; it does not abort the close.
            // A month with no KSeF connection still needs its ZUS figure.
            $outcome = 'błąd';
            $status = 'error';
            $error = $e->getMessage();
        }

        return [
            'step' => $name,
            'status' => $status,
            'outcome' => $outcome,
            'error' => $error,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
