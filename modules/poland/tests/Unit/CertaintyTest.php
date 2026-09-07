<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Certainty\Caveat;
use Poland\Certainty\CertaintyReport;
use Poland\Certainty\DataCertainty;
use Poland\Ksef\Contracts\KsefSession;
use Poland\Ksef\KsefScope;
use Poland\Ksef\KsefSyncResult;
use Poland\Ksef\UnconfiguredKsefClient;

final class CertaintyTest extends TestCase
{
    public function test_combining_certainties_takes_the_worst_never_an_average(): void
    {
        // A report is exactly as trustworthy as its least trustworthy input.
        // Averaging would let one missing bank statement disappear behind nine
        // reconciled ones.
        self::assertSame(
            DataCertainty::NotEnoughData,
            DataCertainty::worst(
                DataCertainty::Verified,
                DataCertainty::Verified,
                DataCertainty::NotEnoughData,
                DataCertainty::Calculated,
            ),
        );
    }

    public function test_only_verified_counts_as_final(): void
    {
        self::assertTrue(DataCertainty::Verified->isFinal());
        self::assertFalse(DataCertainty::Calculated->isFinal());
        self::assertFalse(DataCertainty::RequiresReview->isFinal());
        self::assertFalse(DataCertainty::NotEnoughData->isFinal());
    }

    public function test_no_caveats_and_no_reconciliation_is_calculated_not_verified(): void
    {
        // Getting the arithmetic right is not the same as having checked it
        // against an independent source.
        $report = CertaintyReport::of([]);

        self::assertSame(DataCertainty::Calculated, $report->certainty);
        self::assertFalse($report->isFinal());
    }

    public function test_no_caveats_with_reconciliation_is_verified(): void
    {
        $report = CertaintyReport::of([], reconciled: true);

        self::assertSame(DataCertainty::Verified, $report->certainty);
        self::assertTrue($report->isFinal());
    }

    public function test_the_specified_messages_are_exactly_what_is_shown(): void
    {
        self::assertStringContainsString(
            'koszty mogą być niekompletne',
            Caveat::bankStatementMissing('sierpień 2026')->message,
        );
        self::assertStringContainsString(
            'Synchronizacja z KSeF niedostępna',
            Caveat::ksefUnavailable()->message,
        );
        self::assertStringContainsString(
            'Wyliczenie podatku zablokowane',
            Caveat::ratesUnverified('zus_social')->message,
        );
        self::assertStringContainsString(
            'wymagany przegląd ręczny',
            Caveat::interpretationInconclusive('pismo.pdf')->message,
        );
    }

    public function test_every_caveat_offers_a_way_out(): void
    {
        // A warning with no remedy is a warning people learn to ignore.
        foreach ([
            Caveat::bankStatementMissing('sierpień 2026'),
            Caveat::ksefUnavailable('brak tokenu'),
            Caveat::ratesUnverified('pit'),
            Caveat::interpretationInconclusive('x.pdf'),
            Caveat::newDocumentAfterReport('2026-08', 2),
            Caveat::unmatchedTransactions(3),
            Caveat::engineDisagreesWithInterpretation('ZUS 08/2026'),
        ] as $caveat) {
            self::assertNotNull($caveat->remedy, $caveat->code.' has no remedy');
            self::assertNotSame('', trim($caveat->message));
        }
    }

    public function test_a_new_document_after_a_report_says_the_report_was_not_changed(): void
    {
        $caveat = Caveat::newDocumentAfterReport('2026-08', 3);

        self::assertSame(DataCertainty::RequiresReview, $caveat->certainty);
        self::assertStringContainsString('NIE został zmieniony', $caveat->message);
    }

    public function test_blocking_caveats_are_separable_from_review_ones(): void
    {
        $report = CertaintyReport::of([
            Caveat::unmatchedTransactions(2),
            Caveat::ratesUnverified('vat'),
        ]);

        self::assertSame(DataCertainty::NotEnoughData, $report->certainty);
        self::assertCount(1, $report->blocking());
        self::assertSame('rates_unverified', $report->blocking()[0]->code);
    }

    public function test_only_invoice_read_is_an_allowed_scope(): void
    {
        self::assertSame([KsefScope::InvoiceRead], KsefScope::allowed());
        self::assertTrue(KsefScope::InvoiceRead->isAllowed());
        self::assertFalse(KsefScope::InvoiceWrite->isAllowed());
        self::assertFalse(KsefScope::CredentialsManage->isAllowed());
    }

    public function test_a_write_scope_is_refused_with_an_explanation(): void
    {
        $this->expectExceptionMessageMatches('/nie jest dozwolony/');
        KsefScope::InvoiceWrite->assertAllowed();
    }

    public function test_an_unconfigured_ksef_client_refuses_rather_than_returning_nothing(): void
    {
        // An empty page is indistinguishable from "you had no purchases this
        // month", which would quietly understate costs.
        $client = new UnconfiguredKsefClient();

        self::assertFalse($client->isConfigured());
        self::assertSame(KsefScope::InvoiceRead, $client->scope());

        $this->expectExceptionMessageMatches('/NIE oznacza/u');
        $client->openSession();
    }

    public function test_a_partial_sync_reports_where_to_resume_and_is_not_clean(): void
    {
        $result = new KsefSyncResult(
            from: new \DateTimeImmutable('2026-08-01'),
            to: new \DateTimeImmutable('2026-08-31'),
            importedNumbers: ['A', 'B'],
            failures: ['C' => 'timeout'],
            completed: false,
            stoppedBecause: 'połączenie przerwane',
            resumeFrom: new \DateTimeImmutable('2026-08-01'),
        );

        self::assertFalse($result->isClean());
        self::assertStringContainsString('PRZERWANA', $result->summary());
        self::assertStringContainsString('nic nie zostało pominięte', $result->summary());
    }

    public function test_a_sync_with_failures_is_never_reported_as_clean(): void
    {
        $result = new KsefSyncResult(
            from: new \DateTimeImmutable('2026-08-01'),
            to: new \DateTimeImmutable('2026-08-31'),
            importedNumbers: ['A', 'B', 'C'],
            failures: ['D' => 'parse error'],
        );

        self::assertSame(3, $result->importedCount());
        self::assertFalse($result->isClean(), '3 imported and 1 failed is not a success');
    }

    public function test_a_session_never_leaks_its_token(): void
    {
        $session = new KsefSession('SECRET-TOKEN', 'SES-1', new \DateTimeImmutable());

        self::assertStringNotContainsString('SECRET-TOKEN', (string) $session);
        self::assertStringNotContainsString('SECRET-TOKEN', print_r($session, true));
        self::assertStringNotContainsString('SECRET-TOKEN', var_export($session->__debugInfo(), true));
        // The one deliberate way to get it.
        self::assertSame('SECRET-TOKEN', $session->reveal());
    }
}
