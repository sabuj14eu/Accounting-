<?php

declare(strict_types=1);

namespace Shop\Truth;

use InvalidArgumentException;

/**
 * A difference the system found and cannot explain by itself.
 *
 * The specification says this in three separate places, so it is worth stating
 * once as a rule of the type system rather than three times as a habit: a
 * shortfall in cash, in stock or in a platform payout is REQUIRES REVIEW and
 * never an accusation. The constructor refuses accusatory vocabulary outright —
 * not to be clever, but because the sentence that gets written at 1 a.m. by
 * somebody adding a feature is the sentence that ends up in front of an
 * employee.
 *
 * Every flag must also carry innocent explanations. A flag with no benign
 * reading is not a finding, it is an insinuation, so the constructor rejects it.
 */
final class ReviewFlag implements \JsonSerializable
{
    public const SEVERITY_INFO = 'INFO';
    public const SEVERITY_REVIEW = 'REQUIRES_REVIEW';
    public const SEVERITY_URGENT = 'URGENT_REVIEW';

    /**
     * Words that assert wrongdoing. Matched case-insensitively, Polish and
     * English, on the explanation the user sees.
     */
    private const ACCUSATORY = [
        'theft', 'thief', 'stole', 'stolen', 'stealing', 'fraud', 'fraudulent',
        'embezzl', 'skimming', 'dishonest', 'pocketed',
        'kradzież', 'kradziez', 'kradł', 'ukradł', 'złodziej', 'zlodziej',
        'oszustwo', 'oszust', 'defraudacja', 'nieuczciw',
    ];

    /** @param list<string> $possibleExplanations */
    public function __construct(
        public readonly string $code,
        public readonly string $subject,
        public readonly string $explanation,
        public readonly array $possibleExplanations,
        public readonly string $severity = self::SEVERITY_REVIEW,
        public readonly ?Money $financialImpact = null,
    ) {
        if (! preg_match('/^[A-Z][A-Z0-9_]+$/', $code)) {
            throw new InvalidArgumentException("Review codes are uppercase constants; got '{$code}'.");
        }

        if ($possibleExplanations === []) {
            throw new InvalidArgumentException(
                "Review flag {$code} lists no innocent explanation. A difference the system cannot "
                .'explain benignly is not a finding it is entitled to raise.'
            );
        }

        foreach ([$explanation, ...$possibleExplanations] as $text) {
            self::assertNotAccusatory($text, $code);
        }

        if (! in_array($severity, [self::SEVERITY_INFO, self::SEVERITY_REVIEW, self::SEVERITY_URGENT], true)) {
            throw new InvalidArgumentException("Unknown severity '{$severity}'.");
        }
    }

    private static function assertNotAccusatory(string $text, string $code): void
    {
        $haystack = mb_strtolower($text);
        foreach (self::ACCUSATORY as $word) {
            if (str_contains($haystack, $word)) {
                throw new InvalidArgumentException(
                    "Review flag {$code} accuses somebody ('{$word}'). This application reports a "
                    .'difference and lists what could cause it; it does not name a cause it cannot prove.'
                );
            }
        }
    }

    public function withImpact(Money $impact): self
    {
        return new self(
            $this->code,
            $this->subject,
            $this->explanation,
            $this->possibleExplanations,
            $this->severity,
            $impact,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'subject' => $this->subject,
            'explanation' => $this->explanation,
            'possible_explanations' => $this->possibleExplanations,
            'severity' => $this->severity,
            'financial_impact' => $this->financialImpact?->grosze,
        ];
    }
}
