<?php

declare(strict_types=1);

namespace Poland\Reconciliation;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * A document a bank transaction might be paying.
 *
 * A flat shape on purpose: KSeF invoices, recorded costs and payment
 * obligations all become this, so the matcher has one thing to reason about
 * instead of three.
 */
final class MatchCandidate
{
    /** @param list<string> $references identifiers that might appear in a payment title */
    public function __construct(
        public readonly string $reference,
        public readonly string $type,
        public readonly Money $amount,
        public readonly ?DateTimeImmutable $date = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $counterpartyNip = null,
        public readonly ?string $counterpartyAccount = null,
        public readonly array $references = [],
        public readonly TransactionCategory $category = TransactionCategory::Expense,
        /** True when the taxpayer RECEIVES this money rather than paying it. */
        public readonly bool $incoming = false,
    ) {
    }

    /** @return list<string> */
    public function allReferences(): array
    {
        return array_values(array_unique(array_filter(
            array_merge([$this->reference], $this->references),
            static fn (?string $r): bool => $r !== null && trim($r) !== '',
        )));
    }
}
