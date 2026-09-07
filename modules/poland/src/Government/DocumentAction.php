<?php

declare(strict_types=1);

namespace Poland\Government;

/**
 * What a government document requires of the taxpayer.
 *
 * UNKNOWN is a first-class outcome, not a failure of the classifier. A letter
 * whose meaning is unclear is a letter somebody has to read, and saying so is
 * more useful than a confident guess about a deadline.
 */
enum DocumentAction: string
{
    case PaymentRequired = 'payment_required';
    case ResponseRequired = 'response_required';
    case InformationOnly = 'information_only';
    case PossibleIssue = 'possible_issue';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::PaymentRequired => 'WYMAGA ZAPŁATY',
            self::ResponseRequired => 'WYMAGA ODPOWIEDZI',
            self::InformationOnly => 'INFORMACJA',
            self::PossibleIssue => 'MOŻLIWY PROBLEM PODATKOWY',
            self::Unknown => 'DO PRZEGLĄDU RĘCZNEGO',
        };
    }

    public function englishLabel(): string
    {
        return match ($this) {
            self::PaymentRequired => 'PAYMENT REQUIRED',
            self::ResponseRequired => 'RESPONSE REQUIRED',
            self::InformationOnly => 'INFORMATION ONLY',
            self::PossibleIssue => 'POSSIBLE TAX/ACCOUNTING ISSUE',
            self::Unknown => 'UNKNOWN / MANUAL REVIEW',
        };
    }

    public function isUrgent(): bool
    {
        return in_array($this, [self::PaymentRequired, self::ResponseRequired, self::PossibleIssue], true);
    }
}
