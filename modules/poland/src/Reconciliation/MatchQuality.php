<?php

declare(strict_types=1);

namespace Poland\Reconciliation;

/** How well a transaction lines up with a document. */
enum MatchQuality: string
{
    /** Amount, direction and an identifying reference all agree. */
    case Matched = 'matched';

    /** Close, but something differs — amount, date or counterparty. Needs approval. */
    case Possible = 'possible';

    /** Nothing plausible found. */
    case Unmatched = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'DOPASOWANE',
            self::Possible => 'MOŻLIWE DOPASOWANIE',
            self::Unmatched => 'NIEDOPASOWANE',
        };
    }

    public function needsApproval(): bool
    {
        return $this === self::Possible;
    }
}
