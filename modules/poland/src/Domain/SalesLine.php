<?php

declare(strict_types=1);

namespace Poland\Domain;

use InvalidArgumentException;

/**
 * One VAT designation's worth of gross takings.
 *
 * A fiscal cash register reports GROSS totals per rate letter, so gross is what
 * this object stores and net is always derived. Storing a net figure the user
 * never typed is how a rounding difference becomes a permanent ledger error.
 */
final class SalesLine implements \JsonSerializable
{
    /** @param string $designation A rate as a decimal string ("0.23"), or "zw"/"np". */
    public function __construct(
        public readonly string $designation,
        public readonly Money $gross,
        /** Ryczałt rate for this stream; null falls back to the profile default. */
        public readonly ?float $lumpSumRate = null,
        public readonly ?string $note = null,
        /**
         * Where the takings came in: the shop's fiscal register, a delivery
         * platform, or an invoice the shop issued. Sales stay MONTHLY; the
         * channel only splits the month, it never creates a finer grain.
         */
        public readonly string $channel = SalesChannel::SHOP_REGISTER,
    ) {
        if (! SalesChannel::isKnown($channel)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown sales channel "%s". Use one of: %s.',
                $channel,
                implode(', ', SalesChannel::all()),
            ));
        }
        if ($gross->isNegative()) {
            throw new InvalidArgumentException(
                'Sales takings cannot be negative. A refund or correction belongs in a '
                .'correction document, not as a negative line on the daily report.',
            );
        }
        if (! self::isKnownDesignation($designation)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown VAT designation "%s". Use a decimal rate such as "0.23", or "zw" / "np".',
                $designation,
            ));
        }
    }

    public static function isKnownDesignation(string $designation): bool
    {
        if (in_array($designation, ['zw', 'np'], true)) {
            return true;
        }

        return preg_match('/^0\.\d{1,4}$|^0$/', $designation) === 1;
    }

    public function isTaxable(): bool
    {
        return ! in_array($this->designation, ['zw', 'np'], true);
    }

    public function rate(): float
    {
        return $this->isTaxable() ? (float) $this->designation : 0.0;
    }

    /**
     * VAT contained in the gross amount: gross x rate / (1 + rate).
     * "np" (outside the scope of VAT) carries no VAT and is excluded from the
     * VAT return entirely; "zw" carries none either but does count toward the
     * turnover that governs the subject exemption.
     */
    public function vat(): Money
    {
        if (! $this->isTaxable()) {
            return Money::zero();
        }

        $rate = $this->rate();

        return $this->gross->times($rate / (1 + $rate));
    }

    public function net(): Money
    {
        return $this->gross->minus($this->vat());
    }

    public function jsonSerialize(): array
    {
        return [
            'designation' => $this->designation,
            'gross' => $this->gross,
            'net' => $this->net(),
            'vat' => $this->vat(),
            'lump_sum_rate' => $this->lumpSumRate,
            'note' => $this->note,
            'channel' => $this->channel,
        ];
    }
}
