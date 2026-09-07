<?php

declare(strict_types=1);

namespace Poland\Tests\Feature;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Banking\BankTransaction;
use Poland\Banking\Parsing\Camt053StatementParser;
use Poland\Banking\Parsing\CsvStatementParser;
use Poland\Banking\Parsing\Mt940StatementParser;
use Poland\Banking\Parsing\PdfStatementParser;
use Poland\Domain\Money;
use Poland\Reconciliation\MatchCandidate;
use Poland\Reconciliation\MatchQuality;
use Poland\Reconciliation\TransactionCategory;
use Poland\Reconciliation\TransactionMatcher;

final class ReconciliationTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/'.$name);
    }

    private function tx(
        string $date,
        string $amount,
        string $description,
        ?string $counterparty = null,
        ?string $account = null,
    ): BankTransaction {
        return new BankTransaction(
            new DateTimeImmutable($date),
            Money::parse($amount),
            $description,
            null,
            $counterparty,
            $account,
        );
    }

    private function invoice(string $reference, string $amount, string $date = '2026-08-14'): MatchCandidate
    {
        return new MatchCandidate(
            reference: $reference,
            type: 'ksef_invoice',
            amount: Money::parse($amount),
            date: new DateTimeImmutable($date),
            counterpartyName: 'Hurtownia Wisla Sp. z o.o.',
            counterpartyNip: '1132316939',
            category: TransactionCategory::KsefInvoicePayment,
        );
    }

    // --- statement parsing ------------------------------------------------

    public function test_mt940_reconciles_against_its_own_balances(): void
    {
        // The check that proves no row was dropped. A partial import silently
        // understates costs, and nothing downstream can notice.
        $statement = (new Mt940StatementParser())->parse($this->fixture('statement.mt940'));

        self::assertSame(3, $statement->count());
        self::assertTrue($statement->balancesReconcile());
        self::assertTrue($statement->isTrustworthy());
        self::assertSame('PL61109010140000071219812874', $statement->accountNumber);
    }

    public function test_camt053_reads_direction_from_the_indicator_not_the_sign(): void
    {
        // camt amounts are always positive magnitudes; only CdtDbtInd says which
        // way the money went.
        $statement = (new Camt053StatementParser())->parse($this->fixture('statement.camt053.xml'));

        self::assertTrue($statement->balancesReconcile());
        self::assertTrue($statement->transactions[0]->isDebit());
        self::assertSame(-275734, $statement->transactions[0]->amount->grosze);
        self::assertTrue($statement->transactions[2]->isCredit());
    }

    public function test_csv_reports_an_unreadable_row_instead_of_dropping_it(): void
    {
        $statement = (new CsvStatementParser())->parse($this->fixture('statement-mbank.csv'));

        self::assertSame(3, $statement->count());
        self::assertCount(1, $statement->problems);
        self::assertStringContainsString('Wiersz 5 pominięty', $statement->problems[0]);
        // A statement with an unread row is not trustworthy, even though three
        // rows came through fine.
        self::assertFalse($statement->isTrustworthy());
    }

    public function test_csv_without_a_recognisable_header_refuses_rather_than_guessing(): void
    {
        $statement = (new CsvStatementParser())->parse("a;b;c\n1;2;3\n4;5;6\n");

        self::assertSame(0, $statement->count());
        self::assertNotEmpty($statement->problems);
    }

    public function test_pdf_is_refused_with_a_route_forward(): void
    {
        $statement = (new PdfStatementParser())->parse('%PDF-1.7 ...', 'wyciag.pdf');

        self::assertSame(0, $statement->count());
        self::assertNotEmpty(array_filter(
            $statement->problems,
            static fn (string $p): bool => str_contains($p, 'CSV, MT940'),
        ));
    }

    public function test_the_same_statement_imported_twice_produces_identical_fingerprints(): void
    {
        $parser = new Mt940StatementParser();
        $first = $parser->parse($this->fixture('statement.mt940'));
        $second = $parser->parse($this->fixture('statement.mt940'));

        self::assertSame(
            array_map(static fn ($t) => $t->fingerprint(), $first->transactions),
            array_map(static fn ($t) => $t->fingerprint(), $second->transactions),
        );
    }

    // --- matching ---------------------------------------------------------

    public function test_amount_to_the_grosz_plus_a_reference_is_a_match(): void
    {
        $result = (new TransactionMatcher())->classify(
            $this->tx('2026-08-20', '-5970.00', 'FV/2026/08/417 Hurtownia Wisla'),
            [$this->invoice('FV/2026/08/417', '5970.00')],
        );

        self::assertSame(MatchQuality::Matched, $result->quality);
        self::assertSame(TransactionCategory::KsefInvoicePayment, $result->category);
        self::assertTrue($result->isAutoBookable());
        self::assertStringContainsString('co do grosza', $result->reason());
        self::assertStringContainsString('występuje w tytule', $result->reason());
    }

    public function test_a_reference_typed_with_different_separators_still_matches(): void
    {
        $result = (new TransactionMatcher())->classify(
            $this->tx('2026-08-20', '-5970.00', 'zaplata FV 2026 08 417'),
            [$this->invoice('FV/2026/08/417', '5970.00')],
        );

        self::assertSame(MatchQuality::Matched, $result->quality);
    }

    public function test_the_right_amount_without_a_reference_is_only_possible(): void
    {
        // Two invoices for the same round sum in one month are ordinary. Picking
        // one by amount alone is a coin toss dressed up as a decision.
        $result = (new TransactionMatcher())->classify(
            $this->tx('2026-08-20', '-5970.00', 'przelew'),
            [$this->invoice('FV/2026/08/417', '5970.00')],
        );

        self::assertSame(MatchQuality::Possible, $result->quality);
        self::assertFalse($result->isAutoBookable());
        self::assertTrue($result->quality->needsApproval());
    }

    public function test_a_payment_in_the_wrong_direction_never_matches(): void
    {
        // Money arriving cannot be the payment of an invoice you owe.
        $result = (new TransactionMatcher())->classify(
            $this->tx('2026-08-20', '5970.00', 'FV/2026/08/417'),
            [$this->invoice('FV/2026/08/417', '5970.00')],
        );

        self::assertSame(TransactionCategory::NeedsReview, $result->category);
        self::assertSame(MatchQuality::Unmatched, $result->quality);
    }

    public function test_an_unmatched_transaction_goes_to_review_and_is_never_booked(): void
    {
        $result = (new TransactionMatcher())->classify(
            $this->tx('2026-08-20', '-1234.56', 'Zakup w sklepie internetowym'),
            [$this->invoice('FV/2026/08/417', '5970.00')],
        );

        self::assertSame(TransactionCategory::NeedsReview, $result->category);
        self::assertFalse($result->category->isBookable());
        self::assertFalse($result->isAutoBookable());
        self::assertStringContainsString('NIE została zaksięgowana', $result->reason());
    }

    public function test_zus_and_tax_payments_are_recognised_from_the_title(): void
    {
        $matcher = new TransactionMatcher();

        $zus = $matcher->classify($this->tx('2026-08-03', '-2757.34', 'Skladki ZUS 08/2026'), []);
        self::assertSame(TransactionCategory::ZusPayment, $zus->category);
        // Recognising a category is not matching a document, and it says so.
        self::assertSame(MatchQuality::Possible, $zus->quality);
        self::assertStringContainsString('Nie powiązano z konkretnym dokumentem', $zus->reason());

        $tax = $matcher->classify($this->tx('2026-08-19', '-594.00', 'mikrorachunek podatek PIT-28'), []);
        self::assertSame(TransactionCategory::TaxPayment, $tax->category);
    }

    public function test_one_invoice_is_not_matched_by_two_transactions(): void
    {
        $matcher = new TransactionMatcher();

        $results = $matcher->classifyAll(
            [
                $this->tx('2026-08-20', '-5970.00', 'FV/2026/08/417 Hurtownia'),
                $this->tx('2026-08-21', '-5970.00', 'FV/2026/08/417 Hurtownia'),
            ],
            [$this->invoice('FV/2026/08/417', '5970.00')],
        );

        $matched = array_filter($results, static fn ($r): bool => $r->quality === MatchQuality::Matched);
        self::assertCount(1, $matched, 'Exactly one transaction may claim the invoice.');

        $review = array_filter(
            $results,
            static fn ($r): bool => $r->category === TransactionCategory::NeedsReview,
        );
        self::assertCount(1, $review);
        self::assertStringContainsString('już dopasowany', reset($review)->reason());
    }

    public function test_a_payment_far_outside_the_date_window_is_penalised(): void
    {
        $result = (new TransactionMatcher())->classify(
            $this->tx('2027-03-01', '-5970.00', 'FV/2026/08/417'),
            [$this->invoice('FV/2026/08/417', '5970.00', '2026-08-14')],
        );

        self::assertSame(MatchQuality::Possible, $result->quality, 'Too far apart for an automatic match');
        self::assertStringContainsString('UWAGA', $result->reason());
    }

    public function test_every_classification_carries_its_evidence(): void
    {
        $result = (new TransactionMatcher())->classify(
            $this->tx('2026-08-20', '-5970.00', 'FV/2026/08/417 Hurtownia Wisla'),
            [$this->invoice('FV/2026/08/417', '5970.00')],
        );

        $json = $result->jsonSerialize();
        foreach (['classification', 'confidence', 'reason', 'matched_document', 'source_transaction'] as $key) {
            self::assertArrayHasKey($key, $json, "A classification must carry {$key}");
        }
        self::assertSame('FV/2026/08/417', $json['matched_document']);
        self::assertSame('ksef_invoice', $json['matched_document_type']);
    }

    public function test_an_end_to_end_month_reconciles_from_a_real_statement(): void
    {
        $statement = (new Mt940StatementParser())->parse($this->fixture('statement.mt940'));

        $results = (new TransactionMatcher())->classifyAll($statement->transactions, [
            $this->invoice('FV/2026/08/417', '5970.00'),
        ]);

        self::assertCount(3, $results);
        self::assertSame(TransactionCategory::ZusPayment, $results[0]->category);
        self::assertSame(MatchQuality::Matched, $results[1]->quality);
        // Cash takings arriving match no document — correctly to review.
        self::assertSame(TransactionCategory::NeedsReview, $results[2]->category);
    }
}
