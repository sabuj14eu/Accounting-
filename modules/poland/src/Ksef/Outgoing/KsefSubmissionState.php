<?php

declare(strict_types=1);

namespace Poland\Ksef\Outgoing;

/**
 * The local lifecycle of one invoice submission. KSeF's own status code is
 * stored beside it, never instead of it.
 *
 * Transitions are explicit; anything not listed is refused, so a status
 * poll can never move an ACCEPTED invoice back to PROCESSING and a stray
 * job can never send a BLOCKED one.
 */
enum KsefSubmissionState: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case Blocked = 'BLOCKED';
    case Ready = 'READY';
    case Submitted = 'SUBMITTED';
    case Processing = 'PROCESSING';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case ManualReview = 'MANUAL_REVIEW';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Validated, self::Blocked, self::Cancelled],
            self::Validated => [self::Ready, self::Draft, self::Cancelled],
            self::Blocked => [self::Draft, self::Cancelled],
            self::Ready => [self::Submitted, self::Validated, self::Cancelled, self::ManualReview],
            // SUBMITTED → READY only when the failure proves nothing reached KSeF.
            self::Submitted => [self::Processing, self::Accepted, self::Rejected, self::ManualReview, self::Ready],
            self::Processing => [self::Processing, self::Accepted, self::Rejected, self::ManualReview],
            self::ManualReview => [self::Accepted, self::Rejected, self::Ready, self::Cancelled, self::Processing],
            self::Accepted, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function assertTransition(self $next): void
    {
        if (! $this->canTransitionTo($next)) {
            throw new \LogicException(sprintf('Przejście stanu KSeF %s → %s nie jest dozwolone.', $this->value, $next->value));
        }
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function isSendable(): bool
    {
        return $this === self::Ready;
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Submitted, self::Processing], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'SZKIC',
            self::Validated => 'ZWALIDOWANA',
            self::Blocked => 'ZABLOKOWANA',
            self::Ready => 'GOTOWA DO WYSYŁKI',
            self::Submitted => 'WYSŁANA',
            self::Processing => 'PRZETWARZANA',
            self::Accepted => 'PRZYJĘTA',
            self::Rejected => 'ODRZUCONA',
            self::Cancelled => 'ANULOWANA',
            self::ManualReview => 'PRZEGLĄD RĘCZNY',
        };
    }
}
