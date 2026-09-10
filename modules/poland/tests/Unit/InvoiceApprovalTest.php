<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Poland\Purchases\ApprovalGate;
use Poland\Purchases\ApprovalStatus;
use Poland\Purchases\CostCategory;
use Poland\Purchases\PostingPlan;
use Poland\Tests\Support\InvoiceFixtures;

/**
 * Section 3 of the design: every invoice enters review, and the Approve button
 * exists only when the gate has nothing to object to.
 */
final class InvoiceApprovalTest extends TestCase
{
    private function plan(\Poland\Ksef\Parsing\ParsedInvoice $invoice, ?array $resolutions = null): PostingPlan
    {
        return PostingPlan::build(
            $invoice,
            $resolutions ?? InvoiceFixtures::drinksResolutions($invoice),
            new DateTimeImmutable('2026-08-11 09:52:00'),
            3,
            CostCategory::GoodsForResaleAndMaterials,
        );
    }

    public function test_a_complete_consistent_invoice_with_a_postable_plan_may_be_approved(): void
    {
        $invoice = InvoiceFixtures::drinks();

        $assessment = (new ApprovalGate())->assess($invoice, $this->plan($invoice));

        self::assertTrue($assessment->canApprove(), implode(' | ', $assessment->blockers));
        self::assertSame([], $assessment->blockers);
    }

    public function test_an_incomplete_parse_cannot_be_approved_only_reviewed(): void
    {
        $invoice = InvoiceFixtures::parse('fa-no-namespace-partial.xml', 'P-1');

        $assessment = (new ApprovalGate())->assess($invoice, $this->plan($invoice, []));

        self::assertFalse($assessment->canApprove());
        self::assertStringContainsString('Nie odczytano wszystkich wymaganych pól', $assessment->blockers[0]);
    }

    public function test_lines_that_disagree_with_the_header_block_approval(): void
    {
        $xml = str_replace('<P_11>200.00</P_11>', '<P_11>250.00</P_11>', InvoiceFixtures::xml('fa2-drinks-invoice.xml'));
        $invoice = (new FaInvoiceParser())->parse($xml, 'X-1');

        $assessment = (new ApprovalGate())->assess($invoice, $this->plan($invoice));

        self::assertFalse($assessment->canApprove());
        self::assertTrue((bool) array_filter($assessment->blockers, static fn (string $b): bool => str_contains($b, 'nie sumują się')));
    }

    public function test_a_correction_without_a_linked_original_is_blocked_and_with_one_is_not(): void
    {
        $correction = InvoiceFixtures::parse('fa2-correction-linked.xml', 'KOR-1');
        $plan = $this->plan($correction, InvoiceFixtures::unmapped($correction));

        $unlinked = (new ApprovalGate())->assess($correction, $plan, correctionLinked: false);
        self::assertFalse($unlinked->canApprove());
        self::assertStringContainsString('korygująca', $unlinked->blockers[0]);

        $linked = (new ApprovalGate())->assess($correction, $plan, correctionLinked: true);
        self::assertTrue($linked->canApprove(), implode(' | ', $linked->blockers));
    }

    public function test_a_possible_duplicate_is_blocked_until_a_person_decides(): void
    {
        $invoice = InvoiceFixtures::drinks();

        $assessment = (new ApprovalGate())->assess($invoice, $this->plan($invoice), possibleDuplicate: true);

        self::assertFalse($assessment->canApprove());
        self::assertStringContainsString('duplikat', $assessment->blockers[0]);
    }

    public function test_an_already_posted_invoice_cannot_be_approved_again(): void
    {
        $invoice = InvoiceFixtures::drinks();

        $assessment = (new ApprovalGate())->assess($invoice, $this->plan($invoice), alreadyPosted: true);

        self::assertFalse($assessment->canApprove());
        self::assertStringContainsString('już zaksięgowana', $assessment->blockers[0]);
    }

    public function test_a_first_time_supplier_and_unmapped_lines_are_notes_not_blockers(): void
    {
        $invoice = InvoiceFixtures::drinks();
        $plan = $this->plan($invoice, InvoiceFixtures::unmapped($invoice));

        $assessment = (new ApprovalGate())->assess($invoice, $plan, firstTimeSupplier: true);

        self::assertTrue($assessment->canApprove());
        self::assertTrue((bool) array_filter($assessment->notes, static fn (string $n): bool => str_contains($n, 'Pierwsza faktura')));
        self::assertTrue((bool) array_filter($assessment->notes, static fn (string $n): bool => str_contains($n, 'bez przypisanego produktu')));
    }

    public function test_a_plan_that_cannot_post_blocks_the_gate_with_the_plans_own_words(): void
    {
        $invoice = InvoiceFixtures::drinks();
        // Coca-Cola tracked in pieces, sold in "op", and no pack size known yet.
        $resolutions = [
            new \Poland\Purchases\LineResolution($invoice->lines[0], InvoiceFixtures::cocaCola(true, null), 'alias'),
            \Poland\Purchases\LineResolution::unmapped($invoice->lines[1]),
            \Poland\Purchases\LineResolution::unmapped($invoice->lines[2]),
        ];

        $assessment = (new ApprovalGate())->assess($invoice, $this->plan($invoice, $resolutions));

        self::assertFalse($assessment->canApprove());
        self::assertStringContainsString('wielkość opakowania', $assessment->blockers[0]);
    }

    // --- the status machine ---------------------------------------------------

    public function test_every_imported_invoice_enters_review_before_anything_posts(): void
    {
        self::assertSame([ApprovalStatus::AwaitingReview], ApprovalStatus::Imported->allowedNext());
        self::assertFalse(ApprovalStatus::Imported->canTransitionTo(ApprovalStatus::Posted));
        self::assertFalse(ApprovalStatus::AwaitingReview->canTransitionTo(ApprovalStatus::Posted));
        self::assertTrue(ApprovalStatus::AwaitingReview->canTransitionTo(ApprovalStatus::Approved));
        self::assertTrue(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Posted));
    }

    public function test_nothing_goes_backwards_from_posted_except_by_a_correction(): void
    {
        self::assertFalse(ApprovalStatus::Posted->canTransitionTo(ApprovalStatus::AwaitingReview));
        self::assertFalse(ApprovalStatus::Posted->canTransitionTo(ApprovalStatus::Rejected));
        self::assertTrue(ApprovalStatus::Posted->canTransitionTo(ApprovalStatus::Superseded));

        $this->expectException(\RuntimeException::class);
        ApprovalStatus::Posted->assertTransitionTo(ApprovalStatus::AwaitingReview);
    }

    public function test_only_posted_documents_contribute_to_the_registers(): void
    {
        foreach (ApprovalStatus::cases() as $status) {
            self::assertSame($status === ApprovalStatus::Posted, $status->contributesToRegisters(), $status->value);
        }
        self::assertTrue(ApprovalStatus::Rejected->isFinal());
        self::assertTrue(ApprovalStatus::Superseded->isFinal());
    }
}
