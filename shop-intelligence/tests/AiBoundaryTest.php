<?php

declare(strict_types=1);

namespace Shop\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Ai\AiAction;
use Shop\Ai\AiBoundary;
use Shop\Ai\AiBoundaryViolation;
use Shop\Ai\PendingSuggestion;
use Shop\Truth\EvidenceType;
use Shop\Truth\Money;
use Shop\Truth\Provenance;

final class AiBoundaryTest extends TestCase
{
    /**
     * R27 — §22, the whole prohibition list, one assertion per forbidden action.
     *
     * Written as a loop over the enum rather than as eleven separate tests so
     * that adding a twelfth forbidden action to the enum automatically adds a
     * twelfth assertion here: a new prohibition cannot be added without also
     * being enforced.
     */
    public function test_r27_every_forbidden_ai_action_throws(): void
    {
        $forbidden = AiBoundary::forbiddenActions();

        $this->assertGreaterThanOrEqual(11, count($forbidden));

        foreach ($forbidden as $action) {
            try {
                AiBoundary::assertPermitted($action, 'regression test');
                $this->fail("AiBoundary allowed the forbidden action {$action->value}.");
            } catch (AiBoundaryViolation $violation) {
                $this->assertStringContainsString($action->value, $violation->getMessage());
                $this->assertStringContainsString('§22', $violation->getMessage());
            }
        }
    }

    public function test_the_prohibition_list_matches_the_specification(): void
    {
        $forbidden = array_map(static fn (AiAction $a) => $a->value, AiBoundary::forbiddenActions());

        foreach ([
            'CHANGE_TRANSACTION',
            'CHANGE_INVENTORY',
            'CREATE_CASH_PAYMENT',
            'CREATE_REVENUE',
            'CREATE_EXPENSE',
            'MARK_INVOICE_PAID',
            'CHANGE_ACCOUNTING_RECORD',
            'CALCULATE_OFFICIAL_TAX',
            'SUBMIT_TO_GOVERNMENT',
            'ACCESS_KSEF_CREDENTIALS',
            'MODIFY_ACCOUNTS_SYSTEM',
        ] as $action) {
            $this->assertContains($action, $forbidden, "§22 forbids {$action} and the enum does not.");
        }
    }

    public function test_reading_extracting_classifying_and_suggesting_are_permitted(): void
    {
        foreach ([
            AiAction::READ_DOCUMENT,
            AiAction::EXTRACT_DATA,
            AiAction::CLASSIFY,
            AiAction::SUGGEST_MATCH,
            AiAction::IDENTIFY_ANOMALY,
            AiAction::EXPLAIN,
            AiAction::SUMMARISE,
        ] as $action) {
            AiBoundary::assertPermitted($action);
        }

        $this->addToAssertionCount(7);
    }

    public function test_a_pending_suggestion_is_labelled_ai_until_a_person_accepts_it(): void
    {
        $suggestion = new PendingSuggestion(
            'sug-1',
            AiAction::SUGGEST_MATCH,
            'ING/2026-08-14/00231 → FV/2026/08/114',
            Money::parse('3 000,00'),
            0.82,
            'amount matches to the grosz and the title contains the invoice number',
            'extractor-v1',
        );

        $this->assertSame(PendingSuggestion::PENDING, $suggestion->state());
        $this->assertSame(Provenance::AI_SUGGESTION, $suggestion->asFigureWhilePending()->provenance());

        $accepted = $suggestion->accept(EvidenceType::BANK_CONFIRMED, 'anna', '2026-08-16T09:41:00');

        $this->assertSame(PendingSuggestion::ACCEPTED, $suggestion->state());
        $this->assertSame(Provenance::ANALYTICAL_ESTIMATE, $accepted->provenance());
        $this->assertNotSame(EvidenceType::AI_SUGGESTED, $accepted->evidence);
    }

    public function test_a_person_cannot_accept_a_suggestion_on_the_models_own_authority(): void
    {
        $suggestion = new PendingSuggestion(
            'sug-2',
            AiAction::SUGGEST_MATCH,
            'a match',
            Money::parse('100,00'),
            0.5,
            'because',
            'extractor-v1',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not evidence a person can vouch for/');

        $suggestion->accept(EvidenceType::AI_SUGGESTED, 'anna', '2026-08-16T09:41:00');
    }

    public function test_a_rejection_records_why(): void
    {
        $suggestion = new PendingSuggestion(
            'sug-3',
            AiAction::SUGGEST_MATCH,
            'a match',
            Money::parse('100,00'),
            0.5,
            'because',
            'extractor-v1',
        );

        $suggestion->reject('anna', '2026-08-16T09:41:00', 'wrong supplier');

        $this->assertSame(PendingSuggestion::REJECTED, $suggestion->state());
        $this->assertSame('wrong supplier', $suggestion->jsonSerialize()['decision_reason']);
    }

    public function test_a_suggestion_cannot_be_created_for_a_forbidden_action(): void
    {
        $this->expectException(AiBoundaryViolation::class);

        new PendingSuggestion(
            'sug-4',
            AiAction::MARK_INVOICE_PAID,
            'FV/2026/08/114',
            Money::parse('5 000,00'),
            0.99,
            'the document says PAID',
            'extractor-v1',
        );
    }
}
