<?php

declare(strict_types=1);

namespace Poland\Reporting;

/**
 * Three states, not two.
 *
 * NOTHING TO PAY is its own state and never a zero-amount UNPAID row, because
 * an obligation that is genuinely nil and an obligation somebody has not paid
 * yet call for opposite actions, and rendering them the same way makes the
 * checklist useless exactly when it matters.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case NothingToPay = 'nothing_to_pay';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'DO ZAPŁATY',
            self::Paid => 'ZAPŁACONE',
            self::NothingToPay => 'NIC DO ZAPŁATY',
        };
    }

    public function requiresAction(): bool
    {
        return $this === self::Unpaid;
    }
}
