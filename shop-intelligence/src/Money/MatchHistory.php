<?php

declare(strict_types=1);

namespace Shop\Money;

use Shop\Truth\Money;

/**
 * The full life of one match — §6: "matching must be editable, and the history
 * must be kept: original match, corrected match, who changed it, when."
 *
 * The current match is simply the last revision. Nothing is ever removed, and
 * there is no method that removes anything, which is why the guarantee holds
 * without a migration guarding it.
 */
final class MatchHistory implements \JsonSerializable
{
    /** @var list<MatchRevision> */
    private array $revisions = [];

    public function __construct(
        public readonly string $bankTransactionReference,
        public readonly Money $transactionAmount,
    ) {
    }

    /** The first, system-proposed match. Only valid as revision 1. */
    public function open(
        ?string $documentReference,
        Money $amount,
        float $confidence,
        string $reason,
        string $changedBy,
        string $changedAt,
    ): self {
        if ($this->revisions !== []) {
            throw new \LogicException('This match is already open; use correct() to change it.');
        }

        $this->revisions[] = new MatchRevision(
            1, $documentReference, $amount, $confidence, $reason, $changedBy, $changedAt
        );

        return $this;
    }

    /** A human changes the match. The previous revision stays. */
    public function correct(
        ?string $documentReference,
        Money $amount,
        string $reason,
        string $changedBy,
        string $changedAt,
        string $correctionReason,
    ): self {
        if ($this->revisions === []) {
            throw new \LogicException('Nothing to correct: this match was never opened.');
        }

        if (trim($correctionReason) === '') {
            throw new \InvalidArgumentException('A correction must say why the earlier match was wrong.');
        }

        $this->revisions[] = new MatchRevision(
            count($this->revisions) + 1,
            $documentReference,
            $amount,
            // A human decision is certain about itself; the confidence score
            // belonged to the algorithm that guessed.
            1.0,
            $reason,
            $changedBy,
            $changedAt,
            $correctionReason,
        );

        return $this;
    }

    public function current(): MatchRevision
    {
        if ($this->revisions === []) {
            throw new \LogicException('This match has no revisions.');
        }

        return $this->revisions[count($this->revisions) - 1];
    }

    public function original(): MatchRevision
    {
        if ($this->revisions === []) {
            throw new \LogicException('This match has no revisions.');
        }

        return $this->revisions[0];
    }

    /** @return list<MatchRevision> */
    public function history(): array
    {
        return $this->revisions;
    }

    public function wasCorrected(): bool
    {
        return count($this->revisions) > 1;
    }

    public function jsonSerialize(): array
    {
        return [
            'bank_transaction_reference' => $this->bankTransactionReference,
            'transaction_amount' => $this->transactionAmount->grosze,
            'current' => $this->revisions === [] ? null : $this->current(),
            'corrected' => $this->wasCorrected(),
            'history' => $this->revisions,
        ];
    }
}
