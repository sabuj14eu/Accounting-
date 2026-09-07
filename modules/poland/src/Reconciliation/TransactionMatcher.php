<?php

declare(strict_types=1);

namespace Poland\Reconciliation;

use Poland\Banking\BankTransaction;
use Poland\Domain\Money;

/**
 * Matches bank transactions to documents, deterministically.
 *
 * No model, no learned weights: every point of confidence comes from a stated,
 * checkable fact, and every fact is reported in the reason. That matters because
 * the output feeds a review queue a person has to work through — a score they
 * cannot interrogate is a score they will either rubber-stamp or ignore.
 *
 * The bar for MATCHED is deliberately high: the amount must agree to the grosz
 * AND an identifying reference must appear in the payment title. Everything
 * that is merely plausible is POSSIBLE, which needs approval. Everything else
 * is NEEDS REVIEW, which is never booked.
 */
final class TransactionMatcher
{
    /** Payment titles for public charges, matched by keyword and by account. */
    private const ZUS_KEYWORDS = ['zus', 'zaklad ubezpieczen', 'zakład ubezpieczeń', 'skladki', 'składki'];

    private const TAX_KEYWORDS = ['urzad skarbowy', 'urząd skarbowy', 'mikrorachunek', 'podatek',
                                  'pit-', 'vat-7', 'jpk', 'zaliczka na podatek', 'ryczalt', 'ryczałt'];

    public function __construct(
        /** How many days apart a payment and its document may be and still match. */
        private readonly int $dateWindowDays = 45,
    ) {
    }

    /**
     * @param list<MatchCandidate> $candidates
     */
    public function classify(BankTransaction $transaction, array $candidates): Classification
    {
        $best = null;
        $bestScore = 0.0;
        $bestReasons = [];

        foreach ($candidates as $candidate) {
            [$score, $reasons] = $this->score($transaction, $candidate);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
                $bestReasons = $reasons;
            }
        }

        if ($best !== null && $bestScore >= 0.9) {
            return new Classification(
                $transaction,
                $best->category,
                $bestScore,
                $bestReasons,
                $best->reference,
                $best->type,
                MatchQuality::Matched,
            );
        }

        if ($best !== null && $bestScore >= 0.5) {
            return new Classification(
                $transaction,
                $best->category,
                $bestScore,
                array_merge($bestReasons, [
                    'Dopasowanie niepewne — wymaga zatwierdzenia przez człowieka.',
                ]),
                $best->reference,
                $best->type,
                MatchQuality::Possible,
            );
        }

        // No document matched. A public charge can still be recognised from the
        // payment title alone — that is a CATEGORY, not a match to a document,
        // and it is reported as such.
        $bare = $this->categoriseWithoutDocument($transaction);
        if ($bare !== null) {
            [$category, $confidence, $reason] = $bare;

            return new Classification(
                $transaction,
                $category,
                $confidence,
                [$reason, 'Nie powiązano z konkretnym dokumentem.'],
                null,
                null,
                MatchQuality::Possible,
            );
        }

        return Classification::needsReview(
            $transaction,
            sprintf(
                'Nie znaleziono dokumentu pasującego do przelewu %s z %s ("%s"). '
                .'Transakcja NIE została zaksięgowana.',
                $transaction->amount->format(),
                $transaction->bookingDate->format('d.m.Y'),
                mb_substr($transaction->description, 0, 60),
            ),
        );
    }

    /**
     * @param list<MatchCandidate> $candidates
     * @return list<Classification>
     */
    public function classifyAll(array $transactions, array $candidates): array
    {
        $used = [];
        $results = [];

        // Strongest matches first, so one invoice is not claimed by a weaker
        // candidate transaction before its exact payment is considered.
        $scored = [];
        foreach ($transactions as $index => $transaction) {
            $scored[$index] = $this->classify($transaction, $candidates);
        }

        uasort(
            $scored,
            static fn (Classification $a, Classification $b): int => $b->confidence <=> $a->confidence,
        );

        foreach ($scored as $index => $classification) {
            $reference = $classification->matchedDocument;

            if ($reference !== null && isset($used[$reference])) {
                // The document is already paid by another transaction. Two
                // payments for one invoice is possible but not assumable.
                $results[$index] = Classification::needsReview(
                    $classification->transaction,
                    sprintf(
                        'Dokument %s został już dopasowany do innej transakcji. '
                        .'Druga płatność za ten sam dokument wymaga decyzji człowieka.',
                        $reference,
                    ),
                );

                continue;
            }

            if ($reference !== null && $classification->quality === MatchQuality::Matched) {
                $used[$reference] = true;
            }

            $results[$index] = $classification;
        }

        ksort($results);

        return array_values($results);
    }

    /** @return array{0: float, 1: list<string>} */
    private function score(BankTransaction $transaction, MatchCandidate $candidate): array
    {
        // Direction must agree before anything else is considered: an invoice
        // you pay cannot be matched by money arriving.
        if ($candidate->incoming !== $transaction->isCredit()) {
            return [0.0, []];
        }

        $score = 0.0;
        $reasons = [];

        $magnitude = $transaction->absoluteAmount();

        if ($magnitude->equals($candidate->amount)) {
            $score += 0.55;
            $reasons[] = sprintf('kwota zgadza się co do grosza (%s)', $candidate->amount->format());
        } elseif ($this->within($magnitude, $candidate->amount, 0.02)) {
            $score += 0.20;
            $reasons[] = sprintf(
                'kwota zbliżona: przelew %s, dokument %s',
                $magnitude->format(),
                $candidate->amount->format(),
            );
        } else {
            return [0.0, []];
        }

        $text = $transaction->searchableText();

        $matchedReference = null;
        foreach ($candidate->allReferences() as $reference) {
            if ($this->referenceAppears($reference, $text)) {
                $matchedReference = $reference;
                break;
            }
        }

        if ($matchedReference !== null) {
            $score += 0.35;
            $reasons[] = sprintf('numer "%s" występuje w tytule przelewu', $matchedReference);
        }

        if ($candidate->counterpartyAccount !== null
            && $transaction->counterpartyAccount !== null
            && $this->sameAccount($candidate->counterpartyAccount, $transaction->counterpartyAccount)) {
            $score += 0.15;
            $reasons[] = 'zgodny numer rachunku kontrahenta';
        } elseif ($candidate->counterpartyName !== null
            && $this->nameAppears($candidate->counterpartyName, $text.' '.mb_strtolower($transaction->counterparty ?? ''))) {
            $score += 0.10;
            $reasons[] = sprintf('nazwa kontrahenta "%s" występuje w przelewie', $candidate->counterpartyName);
        }

        if ($candidate->date !== null) {
            $days = (int) $candidate->date->diff($transaction->bookingDate)->days;
            if ($days <= $this->dateWindowDays) {
                $score += 0.05;
                $reasons[] = sprintf('data w oknie %d dni od dokumentu', $days);
            } else {
                $score -= 0.20;
                $reasons[] = sprintf('UWAGA: przelew %d dni od daty dokumentu', $days);
            }
        }

        // An amount match alone never reaches MATCHED. Two invoices for the same
        // round sum in one month are ordinary, and picking one by amount is a
        // coin toss dressed up as a decision.
        if ($matchedReference === null) {
            $score = min($score, 0.75);
        }

        return [max(0.0, min(1.0, $score)), $reasons];
    }

    /** @return array{0: TransactionCategory, 1: float, 2: string}|null */
    private function categoriseWithoutDocument(BankTransaction $transaction): ?array
    {
        if (! $transaction->isDebit()) {
            return null;
        }

        $text = $transaction->searchableText();

        foreach (self::ZUS_KEYWORDS as $keyword) {
            if (str_contains($text, $keyword)) {
                return [
                    TransactionCategory::ZusPayment,
                    0.7,
                    sprintf('tytuł przelewu wskazuje na ZUS ("%s")', $keyword),
                ];
            }
        }

        foreach (self::TAX_KEYWORDS as $keyword) {
            if (str_contains($text, $keyword)) {
                return [
                    TransactionCategory::TaxPayment,
                    0.7,
                    sprintf('tytuł przelewu wskazuje na podatek ("%s")', $keyword),
                ];
            }
        }

        return null;
    }

    private function within(Money $a, Money $b, float $tolerance): bool
    {
        if ($b->isZero()) {
            return $a->isZero();
        }

        $difference = abs($a->grosze - $b->grosze);

        return $difference <= abs((int) round($b->grosze * $tolerance));
    }

    /**
     * Whether a document number appears in a payment title.
     *
     * Compared with separators stripped, because a payer typing "FV 2026 08 417"
     * means the same invoice as "FV/2026/08/417" and treating those as different
     * sends a perfectly good match to the review queue.
     */
    private function referenceAppears(string $reference, string $haystack): bool
    {
        $normalise = static fn (string $v): string
            => mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $v) ?? $v);

        $needle = $normalise($reference);

        // Too short to be distinctive: "12" would match almost any title.
        if (mb_strlen($needle) < 4) {
            return false;
        }

        return str_contains($normalise($haystack), $needle);
    }

    private function sameAccount(string $a, string $b): bool
    {
        $digits = static fn (string $v): string => preg_replace('/\D/', '', $v) ?? '';

        $left = $digits($a);
        $right = $digits($b);

        return $left !== '' && $left === $right;
    }

    private function nameAppears(string $name, string $haystack): bool
    {
        $words = preg_split('/\s+/u', mb_strtolower($name)) ?: [];

        foreach ($words as $word) {
            // Skip legal-form noise that matches thousands of companies.
            if (mb_strlen($word) < 4 || in_array($word, ['spolka', 'spółka', 'z.o.o', 'sp.', 'z', 'o.o.'], true)) {
                continue;
            }
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }
}
