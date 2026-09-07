<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Poland\Domain\Money;
use Poland\Reporting\ObligationKind;
use Poland\Reporting\PaymentStatus;

/**
 * One line of the payment checklist as stored.
 *
 * A PAID row is never overwritten by a recalculation. If a correction changes
 * what was owed after somebody already paid, the row keeps the payment and the
 * difference becomes visible as a shortfall or an overpayment — because
 * silently rewriting a paid obligation loses the only record that a payment
 * was made at all.
 */
class PaymentObligationModel extends Model
{
    protected $table = 'pl_payment_obligations';

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'date',
        'due_date_verified' => 'bool',
        'paid_at' => 'datetime',
    ];

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Unpaid->value);
    }

    public function kindEnum(): ObligationKind
    {
        return ObligationKind::from($this->kind);
    }

    public function statusEnum(): PaymentStatus
    {
        return PaymentStatus::from($this->status);
    }

    public function isPaid(): bool
    {
        return $this->statusEnum() === PaymentStatus::Paid;
    }

    public function amountMoney(): Money
    {
        return Money::parse((string) $this->amount);
    }

    public function surplusMoney(): Money
    {
        return Money::parse((string) ($this->surplus ?? '0'));
    }

    public function hasSurplus(): bool
    {
        return $this->surplusMoney()->isPositive();
    }

    /** Positive when less was paid than was due. */
    public function shortfall(): ?Money
    {
        if ($this->amount_paid === null) {
            return null;
        }

        return $this->amountMoney()->minus(Money::parse((string) $this->amount_paid));
    }

    public function headline(): string
    {
        if ($this->hasSurplus()) {
            return sprintf('NADWYŻKA %s — nic do zapłaty', $this->surplusMoney()->format());
        }

        return match ($this->statusEnum()) {
            PaymentStatus::NothingToPay => 'Nic do zapłaty',
            PaymentStatus::Paid => sprintf(
                'Zapłacone %s%s',
                $this->paid_at?->format('d.m.Y') ?? '',
                $this->payment_reference !== null ? ' · '.$this->payment_reference : '',
            ),
            PaymentStatus::Unpaid => 'Do zapłaty '.$this->amountMoney()->format(),
        };
    }
}
