<?php

declare(strict_types=1);

namespace Poland\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Poland\Domain\Money;
use Poland\Government\DocumentAction;
use Poland\Government\DocumentClassifier;
use Poland\Government\GovernmentAuthority;
use Poland\Government\UnavailableTextExtractor;
use Poland\Interpretation\InterpretationBoundary;
use Poland\Interpretation\Suggestion;

final class GovernmentInboxTest extends TestCase
{
    private function classifier(): DocumentClassifier
    {
        return new DocumentClassifier();
    }

    public function test_a_zus_demand_for_payment_is_classified_and_dated(): void
    {
        $document = $this->classifier()->classify(
            'ZAKŁAD UBEZPIECZEŃ SPOŁECZNYCH Oddział w Warszawie. UPOMNIENIE. '
            .'Wzywamy do zapłaty zaległości z tytułu składek za okres 08/2026 '
            .'w kwocie 2757,34 zł do dnia 30.09.2026.',
            'zus-upomnienie.pdf',
        );

        self::assertSame(GovernmentAuthority::Zus, $document->authority);
        self::assertSame(DocumentAction::PaymentRequired, $document->action);
        self::assertSame('PAYMENT REQUIRED', $document->action->englishLabel());
        self::assertSame(275734, $document->amount->grosze);
        self::assertSame('2026-09-30', $document->paymentDeadline->format('Y-m-d'));
        self::assertSame('2026-08', $document->taxPeriod);
    }

    public function test_a_request_for_explanation_is_response_required(): void
    {
        $document = $this->classifier()->classify(
            'Naczelnik Urzędu Skarbowego. Czynności sprawdzające. '
            .'Wzywamy do złożenia wyjaśnień w terminie 7 dni.',
            'us-wezwanie.pdf',
        );

        self::assertSame(GovernmentAuthority::UrzadSkarbowy, $document->authority);
        self::assertSame(DocumentAction::ResponseRequired, $document->action);
        self::assertTrue($document->action->isUrgent());
    }

    public function test_a_letter_stating_days_not_a_date_does_not_invent_a_deadline(): void
    {
        // "within 14 days" depends on the service date, which the letter does not
        // state. Turning it into a date would produce a deadline nobody can check.
        $document = $this->classifier()->classify(
            'Urząd Skarbowy. Wzywamy do udzielenia informacji w terminie 14 dni.',
            'wezwanie.pdf',
        );

        self::assertNull($document->responseDeadline);
        self::assertArrayHasKey('deadline', $document->extracted);
        self::assertSame('deadline_days', $document->extracted['deadline']->field);
        self::assertStringContainsString('nie datę', $document->extracted['deadline']->reason);
    }

    public function test_an_unrecognised_letter_is_unknown_not_information_only(): void
    {
        // The dangerous default: quietly filing an unread demand as "information".
        $document = $this->classifier()->classify(
            'Szanowni Państwo, w załączeniu przesyłamy dokumentację.',
            'pismo.pdf',
        );

        self::assertSame(DocumentAction::Unknown, $document->action);
        self::assertSame('UNKNOWN / MANUAL REVIEW', $document->action->englishLabel());
        self::assertTrue($document->needsManualReview());
    }

    public function test_an_unreadable_document_is_kept_and_flagged_never_dropped(): void
    {
        $document = $this->classifier()->unreadable(
            'skan.pdf',
            'brak OCR',
        );

        self::assertTrue($document->textUnavailable);
        self::assertTrue($document->needsManualReview());
        self::assertSame(DocumentAction::Unknown, $document->action);
        self::assertSame('skan.pdf', $document->filename);
    }

    public function test_the_text_extractor_refuses_rather_than_returning_empty_text(): void
    {
        // Empty text would classify as INFORMATION ONLY and a demand for payment
        // would sit in the inbox until the deadline passed.
        $extractor = new UnavailableTextExtractor();

        self::assertFalse($extractor->isAvailable());
        $this->expectExceptionMessageMatches('/DO PRZEGLĄDU RĘCZNEGO/');
        $extractor->extract('%PDF-1.4', 'skan.pdf');
    }

    public function test_a_possible_tax_issue_is_surfaced_as_urgent(): void
    {
        $document = $this->classifier()->classify(
            'Krajowa Administracja Skarbowa. Stwierdzono niezgodność danych w JPK_V7M '
            .'za okres 07/2026 z danymi kontrahentów.',
            'kas.pdf',
        );

        self::assertSame(GovernmentAuthority::Kas, $document->authority);
        self::assertSame(DocumentAction::PossibleIssue, $document->action);
        self::assertTrue($document->action->isUrgent());
        self::assertSame('2026-07', $document->taxPeriod);
    }

    public function test_every_extracted_field_carries_confidence_and_a_reason(): void
    {
        $document = $this->classifier()->classify(
            'ZUS. Wezwanie do zapłaty 1234,56 zł do dnia 15.10.2026 za okres 09/2026.',
            'zus.pdf',
        );

        self::assertNotEmpty($document->extracted);
        foreach ($document->extracted as $name => $suggestion) {
            self::assertInstanceOf(Suggestion::class, $suggestion);
            self::assertNotSame('', $suggestion->reason, "{$name} has no reason");
            self::assertNotSame('', $suggestion->source, "{$name} has no source");
        }
    }

    // --- the AI safety boundary -------------------------------------------

    public function test_an_interpretation_may_never_set_a_tax_figure(): void
    {
        // The whole rule in one test: reading "2757,34 zł do zapłaty" off a
        // letter must not become the ZUS liability.
        $this->expectExceptionMessageMatches('/nie może\s+tworzyć zobowiązania podatkowego/u');

        InterpretationBoundary::assertNoTaxLiability([
            new Suggestion('zus_total', '2757.34', 0.99, 'read from the letter', 'ai'),
        ]);
    }

    public function test_reading_a_stated_amount_is_allowed_because_it_is_evidence(): void
    {
        // "The letter says 2757,34" is a fact about the letter. "You owe
        // 2757,34" is a tax conclusion. Only the second is forbidden.
        InterpretationBoundary::assertNoTaxLiability([
            new Suggestion('stated_amount', '2757.34', 0.8, 'read from the letter', 'ocr'),
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_every_engine_owned_field_is_refused(): void
    {
        foreach (InterpretationBoundary::RESERVED_FOR_ENGINE as $field) {
            try {
                InterpretationBoundary::assertNoTaxLiability([
                    new Suggestion($field, '1.00', 1.0, 'x', 'ai'),
                ]);
                self::fail("Field {$field} should be reserved for the engine.");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    public function test_agreement_between_engine_and_document_is_reported_as_agreement(): void
    {
        $result = InterpretationBoundary::compare(
            Money::parse('2757.34'),
            Money::parse('2757.34'),
            'ZUS 08/2026',
        );

        self::assertTrue($result['agrees']);
        self::assertSame('AGREES', $result['verdict']);
    }

    public function test_disagreement_escalates_and_favours_neither_side(): void
    {
        $result = InterpretationBoundary::compare(
            Money::parse('2757.34'),
            Money::parse('2900.00'),
            'ZUS 08/2026',
        );

        self::assertFalse($result['agrees']);
        self::assertSame('MANUAL REVIEW REQUIRED', $result['verdict']);
        self::assertStringContainsString('ani na korzyść silnika', $result['detail']);
        self::assertStringContainsString('142,66', $result['detail'], 'the difference is stated');
    }

    public function test_one_uncertain_field_makes_the_whole_extraction_unactionable(): void
    {
        // Not an average: one uncertain field in an otherwise confident
        // extraction is exactly the case that needs a human.
        $suggestions = [
            new Suggestion('authority', 'zus', 0.99, 'x', 'c'),
            new Suggestion('action', 'payment_required', 0.95, 'x', 'c'),
            new Suggestion('deadline', '2026-09-30', 0.40, 'ambiguous', 'c'),
        ];

        self::assertFalse(InterpretationBoundary::isActionable($suggestions));
        self::assertTrue(InterpretationBoundary::isActionable(array_slice($suggestions, 0, 2)));
    }

    public function test_an_empty_extraction_is_never_actionable(): void
    {
        self::assertFalse(InterpretationBoundary::isActionable([]));
    }
}
