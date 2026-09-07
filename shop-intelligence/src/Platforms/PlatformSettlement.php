<?php

declare(strict_types=1);

namespace Shop\Platforms;

use Shop\Truth\Figure;
use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;

/**
 * One delivery-platform payout period — §6.
 *
 *     gross orders − platform commission − other fees = expected payout
 *
 * against what the bank actually received. The arithmetic is trivial; the value
 * is in refusing to round the answer into "fine". A 40 zł gap on a 6 000 zł
 * payout is not fine, and it is also not evidence of anything except a 40 zł
 * gap, which is exactly how it is reported.
 */
final class PlatformSettlement implements \JsonSerializable
{
    public const RECONCILED = 'RECONCILED';
    public const UNRECONCILED = 'UNRECONCILED';
    public const REQUIRES_REVIEW = 'REQUIRES_REVIEW';
    public const NO_PAYOUT_RECORDED = 'NO_PAYOUT_RECORDED';

    public readonly Money $tolerance;

    public function __construct(
        public readonly string $platform,
        public readonly string $period,
        public readonly Money $grossOrders,
        public readonly Money $commission,
        public readonly Money $otherFees,
        public readonly ?Figure $bankReceipt = null,
        ?Money $tolerance = null,
    ) {
        $tolerance ??= Money::zero();

        if ($tolerance->isNegative()) {
            throw new \InvalidArgumentException('Tolerance cannot be negative.');
        }

        if ($grossOrders->isNegative() || $commission->isNegative() || $otherFees->isNegative()) {
            throw new \InvalidArgumentException(
                'Gross orders, commission and fees are recorded as positive magnitudes; '
                .'the subtraction happens here, not in the caller.'
            );
        }

        $this->tolerance = $tolerance;
    }

    public function expectedPayout(): Money
    {
        return $this->grossOrders->minus($this->commission)->minus($this->otherFees);
    }

    /** Positive = the shop received LESS than the platform's own numbers imply. */
    public function difference(): ?Money
    {
        if ($this->bankReceipt === null) {
            return null;
        }

        return $this->expectedPayout()->minus($this->bankReceipt->amount);
    }

    public function status(): string
    {
        if ($this->bankReceipt === null) {
            return self::NO_PAYOUT_RECORDED;
        }

        $difference = $this->difference()->absolute();

        if (! $difference->greaterThan($this->tolerance)) {
            return self::RECONCILED;
        }

        // A gap under one percent reads as fees or rounding; above that it is
        // worth a person's time. Neither reading is an accusation.
        $share = $difference->shareOf($this->expectedPayout()->absolute());

        return ($share !== null && $share <= 1.0) ? self::UNRECONCILED : self::REQUIRES_REVIEW;
    }

    public function effectiveCommissionRate(): ?float
    {
        return $this->commission->plus($this->otherFees)->shareOf($this->grossOrders);
    }

    /** @return list<ReviewFlag> */
    public function reviewFlags(): array
    {
        $status = $this->status();

        if ($status === self::RECONCILED) {
            return [];
        }

        if ($status === self::NO_PAYOUT_RECORDED) {
            return [new ReviewFlag(
                'PLATFORM_PAYOUT_NOT_RECORDED',
                $this->platform.' '.$this->period,
                'The platform reports '.$this->expectedPayout()->format()
                    .' due, and no matching receipt has been found on any imported bank statement.',
                [
                    "the payout is still inside the platform's normal settlement delay",
                    'the bank statement covering the payout date has not been imported yet',
                    'the payout landed in a different account from the one imported',
                    'the platform withheld it against an earlier chargeback or refund',
                ],
                ReviewFlag::SEVERITY_REVIEW,
                $this->expectedPayout(),
            )];
        }

        $difference = $this->difference();
        $direction = $difference->isPositive() ? 'less than' : 'more than';

        return [new ReviewFlag(
            'PLATFORM_PAYOUT_MISMATCH',
            $this->platform.' '.$this->period,
            'The bank received '.$difference->absolute()->format().' '.$direction
                ." the platform's own figures imply (".$this->expectedPayout()->format().' expected, '
                .$this->bankReceipt->amount->format().' received).',
            [
                'refunds or customer chargebacks settled in this payout but reported in another period',
                'a marketing or promotion charge deducted outside the commission line',
                'orders from the last day of the period paid out in the next one',
                'an adjustment for a previous period netted off this payout',
                'the commission rate changed mid-period and the statement uses the old one',
            ],
            $status === self::REQUIRES_REVIEW ? ReviewFlag::SEVERITY_REVIEW : ReviewFlag::SEVERITY_INFO,
            $difference->absolute(),
        )];
    }

    public function jsonSerialize(): array
    {
        return [
            'platform' => $this->platform,
            'period' => $this->period,
            'gross_orders' => $this->grossOrders->grosze,
            'commission' => $this->commission->grosze,
            'other_fees' => $this->otherFees->grosze,
            'expected_payout' => $this->expectedPayout()->grosze,
            'bank_receipt' => $this->bankReceipt,
            'difference' => $this->difference()?->grosze,
            'effective_commission_rate' => $this->effectiveCommissionRate(),
            'status' => $this->status(),
            'review_flags' => $this->reviewFlags(),
        ];
    }
}
