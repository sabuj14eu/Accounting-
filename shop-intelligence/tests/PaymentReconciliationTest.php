<?php

declare(strict_types=1);

namespace Shop\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Money\AllocationSet;
use Shop\Money\AllocationState;
use Shop\Money\MatchHistory;
use Shop\Platforms\PlatformSettlement;
use Shop\Truth\EvidenceType;
use Shop\Truth\Figure;
use Shop\Truth\Money;

final class PaymentReconciliationTest extends TestCase
{
    private function fiveThousandInvoice(): AllocationSet
    {
        return (new AllocationSet('FV/2026/08/114', Money::parse('5 000,00'), 'Hurtownia Mięsna'))
            ->allocate(new Figure(
                Money::parse('3 000,00'),
                EvidenceType::BANK_CONFIRMED,
                'Bank transfer 14.08',
                'ING/2026-08-14/00231',
            ))
            ->allocate(new Figure(
                Money::parse('2 000,00'),
                EvidenceType::USER_DECLARED,
                'Owner says paid in cash',
                'declaration/2026-08-20',
            ));
    }

    /**
     * R01 + R02 — the specification's worked example, and the sentence it turns on:
     * "the system must NOT pretend it found the 2 000 PLN cash payment in the bank."
     */
    public function test_r01_r02_a_fully_allocated_invoice_keeps_bank_and_declared_apart(): void
    {
        $invoice = $this->fiveThousandInvoice();

        $this->assertSame(AllocationState::FULLY_ALLOCATED, $invoice->state());
        $this->assertTrue($invoice->outstanding()->isZero());

        // The two halves stay distinguishable for the life of the record.
        $this->assertSame(300000, $invoice->corroborated()->grosze);
        $this->assertSame(200000, $invoice->uncorroborated()->grosze);
        $this->assertFalse($invoice->allocated()->isFullyCorroborated());

        // And no rendering of it can read as "5 000 found in the bank".
        $description = $invoice->settlementDescription();
        $this->assertStringContainsString('BANK_CONFIRMED', $description);
        $this->assertStringContainsString('USER_DECLARED', $description);
        $this->assertStringContainsString('not independently confirmed', $description);

        $codes = array_map(static fn ($f) => $f->code, $invoice->reviewFlags());
        $this->assertContains('SETTLED_PARTLY_ON_DECLARATION', $codes);
    }

    /** R03 — the same payment imported twice must not quietly settle two invoices' worth. */
    public function test_r03_over_allocation_is_detected_and_explained(): void
    {
        $invoice = $this->fiveThousandInvoice()->allocate(new Figure(
            Money::parse('3 000,00'),
            EvidenceType::BANK_CONFIRMED,
            'Same transfer, second import',
            'ING/2026-08-14/00231',
        ));

        $this->assertSame(AllocationState::OVER_ALLOCATED, $invoice->state());
        $this->assertSame(300000, $invoice->overAllocated()->grosze);

        $flag = $invoice->reviewFlags()[0];
        $this->assertSame('OVER_ALLOCATED_DOCUMENT', $flag->code);
        $this->assertNotEmpty($flag->possibleExplanations);
    }

    /** R04 — §22: AI must not declare an invoice paid. */
    public function test_r04_an_ai_suggestion_cannot_settle_a_document(): void
    {
        $invoice = new AllocationSet('FV/2026/08/115', Money::parse('900,00'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/must not declare an invoice paid/');

        $invoice->allocate(new Figure(
            Money::parse('900,00'),
            EvidenceType::AI_SUGGESTED,
            'Model thinks this transfer is for this invoice',
        ));
    }

    /** R05 — §6: original match, corrected match, who changed it, when. All kept. */
    public function test_r05_correcting_a_match_keeps_the_original(): void
    {
        $match = (new MatchHistory('ING/2026-08-14/00231', Money::parse('3 000,00')))
            ->open('FV/2026/08/114', Money::parse('3 000,00'), 0.75, 'amount matches', 'system', '2026-08-15T02:10:00')
            ->correct(
                'FV/2026/08/109',
                Money::parse('3 000,00'),
                'the owner recognised the supplier reference',
                'anna',
                '2026-08-16T09:41:00',
                'the first guess matched only on amount',
            );

        $this->assertTrue($match->wasCorrected());
        $this->assertCount(2, $match->history());

        $this->assertSame('FV/2026/08/114', $match->original()->documentReference);
        $this->assertSame('system', $match->original()->changedBy);
        $this->assertSame(0.75, $match->original()->confidence);

        $this->assertSame('FV/2026/08/109', $match->current()->documentReference);
        $this->assertSame('anna', $match->current()->changedBy);
        $this->assertSame('2026-08-16T09:41:00', $match->current()->changedAt);
        $this->assertSame('the first guess matched only on amount', $match->current()->correctionReason);
    }

    /** R06 — a correction that does not say why cannot be reviewed later. */
    public function test_r06_a_correction_must_state_why_the_earlier_match_was_wrong(): void
    {
        $match = (new MatchHistory('ING/2026-08-14/00231', Money::parse('3 000,00')))
            ->open('FV/2026/08/114', Money::parse('3 000,00'), 0.75, 'amount matches', 'system', '2026-08-15T02:10:00');

        $this->expectException(\InvalidArgumentException::class);
        $match->correct('FV/2026/08/109', Money::parse('3 000,00'), 'moved', 'anna', '2026-08-16T09:41:00', '  ');
    }

    /** R07 — §6: label it UNRECONCILED or REQUIRES REVIEW. Never assume theft. */
    public function test_r07_a_platform_payout_shortfall_is_flagged_without_accusation(): void
    {
        $settlement = new PlatformSettlement(
            'Glovo',
            '2026-08',
            Money::parse('12 000,00'),
            Money::parse('3 600,00'),
            Money::parse('120,00'),
            new Figure(Money::parse('7 480,00'), EvidenceType::BANK_CONFIRMED, 'Glovo payout 05.09'),
        );

        $this->assertSame(Money::parse('8 280,00')->grosze, $settlement->expectedPayout()->grosze);
        $this->assertSame(Money::parse('800,00')->grosze, $settlement->difference()->grosze);
        $this->assertSame(PlatformSettlement::REQUIRES_REVIEW, $settlement->status());

        $flag = $settlement->reviewFlags()[0];
        $this->assertSame('PLATFORM_PAYOUT_MISMATCH', $flag->code);
        $this->assertGreaterThanOrEqual(3, count($flag->possibleExplanations));
        $this->assertSame(Money::parse('800,00')->grosze, $flag->financialImpact->grosze);
    }

    /** R08 — a payout nobody has seen is not a reconciled payout. */
    public function test_r08_a_missing_platform_payout_is_never_reconciled(): void
    {
        $settlement = new PlatformSettlement(
            'Uber Eats',
            '2026-08',
            Money::parse('4 000,00'),
            Money::parse('1 200,00'),
            Money::zero(),
        );

        $this->assertSame(PlatformSettlement::NO_PAYOUT_RECORDED, $settlement->status());
        $this->assertNull($settlement->difference());
        $this->assertSame('PLATFORM_PAYOUT_NOT_RECORDED', $settlement->reviewFlags()[0]->code);
    }

    public function test_a_payout_within_tolerance_reconciles(): void
    {
        $settlement = new PlatformSettlement(
            'Glovo',
            '2026-08',
            Money::parse('12 000,00'),
            Money::parse('3 600,00'),
            Money::parse('120,00'),
            new Figure(Money::parse('8 279,50'), EvidenceType::BANK_CONFIRMED, 'Glovo payout'),
            Money::parse('1,00'),
        );

        $this->assertSame(PlatformSettlement::RECONCILED, $settlement->status());
        $this->assertSame([], $settlement->reviewFlags());
    }
}
