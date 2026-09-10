<?php

declare(strict_types=1);

namespace Poland\Platforms;

/** Whether the platform's own figures and the bank agree. */
enum SettlementStatus: string
{
    /** No receipt matched on any imported statement. Never reconciled. */
    case NoPayoutRecorded = 'no_payout_recorded';

    /** Bank receipt equals the expected payout within tolerance. */
    case Reconciled = 'reconciled';

    /** A difference beyond tolerance. A question with innocent explanations, never a verdict. */
    case RequiresReview = 'requires_review';

    public function label(): string
    {
        return match ($this) {
            self::NoPayoutRecorded => 'BRAK WPŁATY NA WYCIĄGU',
            self::Reconciled => 'UZGODNIONE',
            self::RequiresReview => 'WYMAGA PRZEGLĄDU',
        };
    }
}
