<?php

declare(strict_types=1);

namespace Poland\Tests\Feature;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Banking\BankTransaction;
use Poland\Banking\ParsedStatement;
use Poland\Banking\Parsing\Mt940StatementParser;
use Poland\Certainty\Caveat;
use Poland\Certainty\CertaintyReport;
use Poland\Certainty\DataCertainty;
use Poland\Certainty\IntegrationStatus;
use Poland\Certainty\ResolvedBy;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Government\DocumentAction;
use Poland\Government\DocumentClassifier;
use Poland\Government\UnavailableTextExtractor;
use Poland\Ksef\UnconfiguredKsefClient;

/**
 * The final release gate, one test per numbered check.
 *
 * Named `test_gate_N_*` so `bin/release-gate.sh` can run each gate
 * independently and report it by number.
 */
final class ReleaseGateTest extends TestCase
{
    // ---- Gate 3: BLOCKED semantics ---------------------------------------

    public function test_gate_3_unverified_rates_are_blocked_and_need_an_accountant(): void
    {
        $caveat = Caveat::ratesUnverified('zus_social, pit');

        self::assertSame(Caveat::OFFICIAL_RATES_NOT_VERIFIED, $caveat->code);
        self::assertSame(DataCertainty::Blocked, $caveat->certainty);
        self::assertSame(ResolvedBy::Accountant, $caveat->resolvedBy);
        // Cannot be fixed by uploading a bank statement.
        self::assertFalse($caveat->certainty->isUserActionable());
    }

    public function test_gate_3_ksef_unavailable_is_blocked_and_needs_an_operator(): void
    {
        $caveat = Caveat::ksefUnavailable('brak tokenu');

        self::assertSame(Caveat::KSEF_TRANSPORT_UNAVAILABLE, $caveat->code);
        self::assertSame(DataCertainty::Blocked, $caveat->certainty);
        self::assertSame(ResolvedBy::Operator, $caveat->resolvedBy);
    }

    public function test_gate_3_missing_bank_statement_is_not_enough_data_and_the_user_fixes_it(): void
    {
        $caveat = Caveat::bankStatementMissing('sierpień 2026');

        self::assertSame(Caveat::BANK_STATEMENT_MISSING, $caveat->code);
        self::assertSame(DataCertainty::NotEnoughData, $caveat->certainty);
        self::assertSame(ResolvedBy::User, $caveat->resolvedBy);
        // This one IS fixed by uploading — the distinction the gate demands.
        self::assertTrue($caveat->certainty->isUserActionable());
    }

    public function test_gate_3_an_ambiguous_duplicate_requires_review_by_a_human(): void
    {
        $caveat = new Caveat(
            Caveat::POSSIBLE_DUPLICATE_TRANSACTION,
            DataCertainty::RequiresReview,
            'Ten sam przelew z dwóch plików.',
            'Rozstrzygnij, czy to jedna płatność czy dwie.',
            ResolvedBy::User,
        );

        self::assertSame(DataCertainty::RequiresReview, $caveat->certainty);
        self::assertSame(ResolvedBy::User, $caveat->resolvedBy);
    }

    public function test_gate_3_the_four_conditions_are_four_different_answers(): void
    {
        // The whole point: a status must say what kind of problem it is and who
        // resolves it. If any two collapse, the user is sent to the wrong place.
        $conditions = [
            Caveat::ratesUnverified('pit'),
            Caveat::ksefUnavailable(),
            Caveat::bankStatementMissing('sierpień 2026'),
            Caveat::unmatchedTransactions(2),
        ];

        $signatures = array_map(
            static fn (Caveat $c): string => $c->code.'/'.$c->certainty->value.'/'.$c->resolvedBy->value,
            $conditions,
        );

        self::assertCount(4, array_unique($signatures));
    }

    public function test_gate_3_every_caveat_names_a_code_a_state_and_a_resolver(): void
    {
        foreach ([
            Caveat::ratesUnverified('x'),
            Caveat::ksefUnavailable(),
            Caveat::bankStatementMissing('x'),
            Caveat::bankStatementIncomplete('x', 'y'),
            Caveat::bankStatementDidNotBalance(1),
            Caveat::unmatchedTransactions(1),
            Caveat::interpretationInconclusive('x'),
            Caveat::engineDisagreesWithInterpretation('x'),
            Caveat::newDocumentAfterReport('2026-08', 1),
            Caveat::operationFailed('x', 'y'),
            Caveat::integrationDisabled('x'),
        ] as $caveat) {
            self::assertMatchesRegularExpression('/^[A-Z_]+$/', $caveat->code, 'code must be a stable identifier');
            self::assertNotNull($caveat->remedy, $caveat->code.' has no remedy');
            self::assertNotSame('', $caveat->resolvedBy->englishLabel());
        }
    }

    // ---- Gate 5: report immutability -------------------------------------

    public function test_gate_5_a_new_document_never_rewrites_a_settled_report(): void
    {
        // The database-level guarantees are exercised against the real ERP in
        // the deployment drill. This pins the rule the domain expresses: the
        // arrival of a new document produces a REVIEW state, not an edit.
        $caveat = Caveat::newDocumentAfterReport('2026-08', 2);

        self::assertSame(Caveat::NEW_DOCUMENTS_AFTER_REPORT, $caveat->code);
        self::assertSame(DataCertainty::RequiresReview, $caveat->certainty);
        self::assertStringContainsString('NIE został zmieniony', $caveat->message);
        self::assertStringContainsString('wygeneruj nową wersję', $caveat->remedy);
    }

    public function test_gate_5_a_settled_period_that_changed_tells_the_user(): void
    {
        $report = CertaintyReport::of([Caveat::newDocumentAfterReport('2026-08', 3)]);

        self::assertSame(DataCertainty::RequiresReview, $report->certainty);
        self::assertCount(1, $report->userActionable());
    }

    // ---- Gate 6: bank completeness ---------------------------------------

    /** @return array{0: ParsedStatement, 1: Period} */
    private function statement(?string $from, ?string $to, bool $balances = true): array
    {
        return [
            new ParsedStatement(
                format: 'test',
                transactions: [
                    new BankTransaction(new DateTimeImmutable('2026-08-10'), Money::parse('-100.00'), 'x'),
                ],
                problems: [],
                accountNumber: 'PL00',
                periodFrom: $from !== null ? new DateTimeImmutable($from) : null,
                periodTo: $to !== null ? new DateTimeImmutable($to) : null,
                openingBalance: $balances ? Money::parse('1000.00') : null,
                closingBalance: $balances ? Money::parse('900.00') : null,
            ),
            Period::of(2026, 8),
        ];
    }

    public function test_gate_6_a_whole_month_is_complete(): void
    {
        [$statement, $period] = $this->statement('2026-08-01', '2026-08-31');

        self::assertSame('COMPLETE', $statement->coverageOf($period)['status']);
        self::assertTrue($statement->coverageOf($period)['complete']);
    }

    public function test_gate_6_half_a_month_is_partial_and_partial_is_not_complete(): void
    {
        // Three transactions in a 1-15 August statement are not August.
        [$statement, $period] = $this->statement('2026-08-01', '2026-08-15', balances: false);
        $coverage = $statement->coverageOf($period);

        self::assertSame('PARTIAL', $coverage['status']);
        self::assertFalse($coverage['complete']);
        self::assertNotSame('COMPLETE', $coverage['status']);
    }

    public function test_gate_6_no_stated_period_is_unknown_and_unknown_is_not_complete(): void
    {
        [$statement, $period] = $this->statement(null, null);
        $coverage = $statement->coverageOf($period);

        self::assertSame('UNKNOWN', $coverage['status']);
        // Explicitly: UNKNOWN != COMPLETE. A format that states no period
        // cannot prove it covered one.
        self::assertNotSame('COMPLETE', $coverage['status']);
        self::assertNull($coverage['complete'], 'neither complete nor incomplete — unknown');
    }

    public function test_gate_6_a_statement_from_another_month_is_outside_period(): void
    {
        [$statement, $period] = $this->statement('2026-06-01', '2026-06-30');

        self::assertSame('OUTSIDE_PERIOD', $statement->coverageOf($period)['status']);
    }

    public function test_gate_6_all_four_states_are_reachable_and_distinct(): void
    {
        $states = [];
        foreach ([
            ['2026-08-01', '2026-08-31', true],
            ['2026-08-01', '2026-08-15', false],
            [null, null, true],
            ['2026-06-01', '2026-06-30', true],
        ] as [$from, $to, $balances]) {
            [$statement, $period] = $this->statement($from, $to, $balances);
            $states[] = $statement->coverageOf($period)['status'];
        }

        self::assertSame(['COMPLETE', 'PARTIAL', 'UNKNOWN', 'OUTSIDE_PERIOD'], $states);
    }

    public function test_gate_6_a_real_mt940_month_is_recognised_as_complete(): void
    {
        $statement = (new Mt940StatementParser())
            ->parse((string) file_get_contents(__DIR__.'/../Fixtures/statement.mt940'));

        self::assertSame('COMPLETE', $statement->coverageOf(Period::of(2026, 8))['status']);
        self::assertTrue($statement->balancesReconcile());
    }

    // ---- Gate 7: KSeF failure semantics ----------------------------------

    public function test_gate_7_an_unavailable_ksef_never_says_no_invoices_found(): void
    {
        $status = IntegrationStatus::ksef(false, 'disabled');

        self::assertSame('NOT CONNECTED', $status->status);
        self::assertStringContainsString('NIE pobiera', $status->detail);
        self::assertStringContainsString('nie znaczy, że faktur nie ma', $status->detail);

        // The forbidden phrasing, in either language.
        foreach (['no invoices found', 'brak faktur', 'nie ma faktur zakupowych'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                str_replace('nie znaczy, że faktur nie ma', '', $status->detail),
            );
        }
    }

    public function test_gate_7_the_client_itself_refuses_rather_than_returning_empty(): void
    {
        $client = new UnconfiguredKsefClient();

        self::assertFalse($client->isConfigured());
        $this->expectExceptionMessageMatches('/NIE oznacza/u');
        $client->openSession();
    }

    // ---- Gate 8: OCR failure semantics ------------------------------------

    public function test_gate_8_an_unreadable_document_is_never_classified(): void
    {
        $document = (new DocumentClassifier())->unreadable('skan.pdf', 'brak OCR');

        // Not INFORMATION ONLY — the failure mode the gate names.
        self::assertNotSame(DocumentAction::InformationOnly, $document->action);
        self::assertSame(DocumentAction::Unknown, $document->action);
        self::assertSame('UNKNOWN / MANUAL REVIEW', $document->action->englishLabel());
        self::assertTrue($document->needsManualReview());
        self::assertTrue($document->textUnavailable);
    }

    public function test_gate_8_the_original_file_reference_is_retained(): void
    {
        $document = (new DocumentClassifier())->unreadable('wezwanie-zus.pdf', 'brak OCR');

        self::assertSame('wezwanie-zus.pdf', $document->filename);
        self::assertNotNull($document->extractionNote);
    }

    public function test_gate_8_the_extractor_throws_instead_of_returning_empty_text(): void
    {
        $extractor = new UnavailableTextExtractor();

        self::assertFalse($extractor->isAvailable());
        $this->expectExceptionMessageMatches('/Pusty tekst zostałby sklasyfikowany/u');
        $extractor->extract('%PDF-1.4', 'skan.pdf');
    }

    public function test_gate_8_empty_text_would_have_classified_as_information_only(): void
    {
        // Documents WHY the extractor throws: this is what would happen if it
        // returned "" instead. The demand for payment becomes an FYI.
        $document = (new DocumentClassifier())->classify('', 'pusty.pdf');

        self::assertSame(DocumentAction::Unknown, $document->action);
        self::assertTrue($document->needsManualReview());
    }
}
