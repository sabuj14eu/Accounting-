<?php

declare(strict_types=1);

namespace Poland\Interpretation;

use RuntimeException;

/**
 * The line an interpretation may not cross.
 *
 * An interpreter — an AI model, an OCR pass, a keyword heuristic — may read,
 * extract, classify, summarise, match, suggest and explain. It may NEVER
 * originate a tax liability. Tax is computed by the deterministic engine from
 * recorded facts, and an interpretation's role is to propose which facts to
 * record, for a human to accept.
 *
 * The rule is enforced here rather than trusted to reviewers, because the
 * failure it prevents is silent: a plausible extracted number flowing into a
 * settlement looks exactly like a correct one.
 */
final class InterpretationBoundary
{
    /**
     * Fields an interpretation may never determine, because determining them
     * IS computing tax.
     */
    public const RESERVED_FOR_ENGINE = [
        // ZUS
        'zus_total', 'zus_social', 'zus_health',
        // PIT
        'pit_due', 'pit_base', 'pit_tax', 'pit_liability',
        // VAT — including the surplus. A surplus is a claim on the tax office,
        // and reading one off a letter would create a refund entitlement out of
        // an interpretation.
        'vat_due', 'vat_output', 'vat_input', 'vat_surplus', 'vat_payable',
        // Totals and bases
        'total_due', 'amount_due', 'tax_base', 'contribution_base',
        // Rates
        'rate', 'lump_sum_rate', 'tax_rate', 'exemption_limit',
        // Deadlines the engine derives from statute and the working-day
        // calendar. A date read off a letter is `stated_deadline` — evidence —
        // and must never replace the computed one, or a misread digit becomes
        // the date somebody pays by.
        'payment_deadline', 'due_date', 'filing_deadline', 'advance_due_date',
    ];

    /**
     * Fields an interpretation MAY set: statements about the document, not
     * conclusions about what is owed.
     *
     * Listed so the distinction is visible rather than implied. `stated_amount`
     * is "the letter says 2 757,34"; `zus_total` is "you owe 2 757,34".
     */
    public const EVIDENCE_FIELDS = [
        'stated_amount', 'stated_deadline', 'stated_period', 'stated_reference',
        'authority', 'action', 'document_type', 'case_number', 'tax_period',
        'counterparty', 'deadline_days', 'summary', 'classification',
    ];

    /**
     * @param list<Suggestion> $suggestions
     * @throws RuntimeException if any suggestion tries to set an engine-owned field
     */
    public static function isReserved(string $field): bool
    {
        return in_array($field, self::RESERVED_FOR_ENGINE, true);
    }

    /**
     * @param list<Suggestion> $suggestions
     * @throws RuntimeException if any suggestion tries to set an engine-owned field
     */
    public static function assertNoTaxLiability(array $suggestions): void
    {
        foreach ($suggestions as $suggestion) {
            if (in_array($suggestion->field, self::RESERVED_FOR_ENGINE, true)) {
                throw new RuntimeException(sprintf(
                    'Interpretacja próbowała ustalić pole "%s", które należy do silnika '
                    .'podatkowego. AI może czytać, klasyfikować i sugerować — nie może '
                    .'tworzyć zobowiązania podatkowego. Źródłem prawdy pozostaje '
                    .'deterministyczne wyliczenie z zapisanych faktów.',
                    $suggestion->field,
                ));
            }
        }
    }

    /**
     * Compare what the engine computed with what an interpretation read.
     *
     * Used where a document states an amount the engine also computes — a ZUS
     * demand, a tax-office letter. Agreement raises confidence; disagreement is
     * never resolved in favour of either side, it is escalated to a human.
     *
     * @return array{agrees: bool, verdict: string, detail: string}
     */
    public static function compare(
        \Poland\Domain\Money $engineValue,
        \Poland\Domain\Money $documentValue,
        string $subject,
        ?\Poland\Domain\Money $tolerance = null,
    ): array {
        $tolerance ??= \Poland\Domain\Money::grosze(0);
        $difference = $engineValue->minus($documentValue);
        $absolute = $difference->isNegative()
            ? \Poland\Domain\Money::zero()->minus($difference)
            : $difference;

        if (! $absolute->greaterThan($tolerance)) {
            return [
                'agrees' => true,
                'verdict' => 'AGREES',
                'detail' => sprintf(
                    '%s: silnik %s, dokument %s — zgodne.',
                    $subject,
                    $engineValue->format(),
                    $documentValue->format(),
                ),
            ];
        }

        return [
            'agrees' => false,
            'verdict' => 'MANUAL REVIEW REQUIRED',
            'detail' => sprintf(
                '%s: silnik wyliczył %s, dokument podaje %s (różnica %s). '
                .'Rozbieżności NIE rozstrzyga się automatycznie — ani na korzyść silnika, '
                .'ani dokumentu. Wymagana decyzja człowieka.',
                $subject,
                $engineValue->format(),
                $documentValue->format(),
                $absolute->format(),
            ),
        ];
    }

    /**
     * Whether a set of suggestions is confident enough to act on without a human.
     *
     * Deliberately strict: EVERY suggestion must clear the threshold, not the
     * average. One uncertain field in an otherwise confident extraction is
     * exactly the case that needs looking at.
     *
     * @param list<Suggestion> $suggestions
     */
    public static function isActionable(array $suggestions, float $threshold = 0.85): bool
    {
        if ($suggestions === []) {
            return false;
        }

        foreach ($suggestions as $suggestion) {
            if (! $suggestion->isConfident($threshold)) {
                return false;
            }
        }

        return true;
    }
}
