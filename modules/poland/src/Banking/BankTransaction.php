<?php

declare(strict_types=1);

namespace Poland\Banking;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * One line off a bank statement, as the bank stated it.
 *
 * `amount` is signed: negative is money leaving. Debit/credit is derived from
 * the sign rather than stored twice, so the two can never disagree.
 *
 * Everything the bank did not supply stays null. A missing balance is not zero,
 * and a missing counterparty is not an empty string.
 */
final class BankTransaction implements \JsonSerializable
{
    public function __construct(
        public readonly DateTimeImmutable $bookingDate,
        public readonly Money $amount,
        public readonly string $description,
        public readonly ?DateTimeImmutable $valueDate = null,
        public readonly ?string $counterparty = null,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?string $reference = null,
        public readonly ?Money $balanceAfter = null,
        public readonly ?string $currency = 'PLN',
        /** @var array<string,mixed> The source line, kept whole for audit. */
        public readonly array $raw = [],
    ) {
    }

    public function isDebit(): bool
    {
        return $this->amount->isNegative();
    }

    public function isCredit(): bool
    {
        return $this->amount->isPositive();
    }

    public function direction(): string
    {
        return $this->isDebit() ? 'DEBIT' : 'CREDIT';
    }

    /** Magnitude, for comparison against an invoice total. */
    public function absoluteAmount(): Money
    {
        return $this->isDebit() ? Money::zero()->minus($this->amount) : $this->amount;
    }

    /**
     * Stable identity for duplicate detection across re-imports of the same
     * statement. Deliberately includes the description: two identical-looking
     * transfers on one day for one amount are usually the same row imported
     * twice, and where they genuinely are two payments the reference differs.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->bookingDate->format('Y-m-d'),
            (string) $this->amount->grosze,
            mb_strtolower(preg_replace('/\s+/u', ' ', trim($this->description)) ?? ''),
            $this->counterpartyAccount ?? '',
            $this->reference ?? '',
        ]));
    }

    /** Text a matcher searches for references, NIPs and keywords. */
    public function searchableText(): string
    {
        return mb_strtolower(trim(implode(' ', array_filter([
            $this->description,
            $this->counterparty,
            $this->reference,
        ]))));
    }

    public function jsonSerialize(): array
    {
        return [
            'booking_date' => $this->bookingDate->format('Y-m-d'),
            'value_date' => $this->valueDate?->format('Y-m-d'),
            'amount' => $this->amount,
            'direction' => $this->direction(),
            'description' => $this->description,
            'counterparty' => $this->counterparty,
            'counterparty_account' => $this->counterpartyAccount,
            'reference' => $this->reference,
            'balance_after' => $this->balanceAfter,
            'currency' => $this->currency,
            'fingerprint' => $this->fingerprint(),
        ];
    }
}
