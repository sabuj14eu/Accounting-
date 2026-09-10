<?php

declare(strict_types=1);

namespace Poland\Purchases;

/**
 * Where an incoming invoice is in the review flow.
 *
 *   imported → awaiting_review → approved → posted
 *                    ├──▶ rejected     (not ours / disputed / duplicate) — XML kept
 *                    └──▶ superseded   (a correction replaced it — original kept)
 *
 * Nothing goes backwards silently: un-posting is a reversal posting with a
 * reason, never a status edit.
 */
enum ApprovalStatus: string
{
    case Imported = 'imported';
    case AwaitingReview = 'awaiting_review';
    case Approved = 'approved';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'zaimportowana',
            self::AwaitingReview => 'DO PRZEGLĄDU',
            self::Approved => 'zatwierdzona (jeszcze nie zaksięgowana)',
            self::Posted => 'zaksięgowana',
            self::Rejected => 'odrzucona',
            self::Superseded => 'skorygowana (zastąpiona korektą)',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Imported => [self::AwaitingReview],
            self::AwaitingReview => [self::Approved, self::Rejected],
            self::Approved => [self::Posted, self::AwaitingReview],
            self::Posted => [self::Superseded],
            self::Rejected, self::Superseded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function assertTransitionTo(self $next): void
    {
        if (! $this->canTransitionTo($next)) {
            throw new \RuntimeException(sprintf(
                'Faktura w stanie "%s" nie może przejść do stanu "%s". Cofnięcie księgowania to '
                .'odwrócenie z podaną przyczyną, nie zmiana statusu.',
                $this->value,
                $next->value,
            ));
        }
    }

    /** Counts toward the purchase register and input VAT. Only this one. */
    public function contributesToRegisters(): bool
    {
        return $this === self::Posted;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Superseded], true);
    }
}
