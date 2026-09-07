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
        'zus_total', 'zus_social', 'zus_health',
        'pit_due', 'pit_base', 'pit_tax',
        'vat_due', 'vat_output', 'vat_input',
        'total_due', 'tax_base', 'contribution_base',
        'rate', 'lump_sum_rate', 'exemption_limit',
    ];

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
