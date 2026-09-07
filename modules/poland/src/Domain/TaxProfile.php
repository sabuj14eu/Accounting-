<?php

declare(strict_types=1);

namespace Poland\Domain;

use InvalidArgumentException;
use Poland\Domain\Enums\ContributionDeductionBasis;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatSettlementFrequency;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;

/**
 * Everything about a taxpayer that changes the arithmetic.
 *
 * The engine holds no defaults for any of it. A JDG's liability depends on
 * choices only the taxpayer can make (regime, ryczałt rate, ZUS scheme, VAT
 * status), and guessing one produces a confident wrong answer, so each is
 * required and validated here rather than defaulted downstream.
 */
final class TaxProfile implements \JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly PitRegime $pitRegime,
        public readonly VatStatus $vatStatus,
        public readonly ZusScheme $zusScheme,
        /** First month of business activity — bounds the time-limited ZUS schemes. */
        public readonly Period $businessStartedAt,
        /**
         * Day of that month the activity started. Social contributions are
         * reduced proportionally for an incomplete month; the health
         * contribution is indivisible and is never reduced.
         */
        public readonly int $businessStartedOnDay = 1,
        public readonly ?string $nip = null,
        /** Default ryczałt rate; required when the regime is LumpSum. */
        public readonly ?float $lumpSumRate = null,
        public readonly VatSettlementFrequency $vatSettlement = VatSettlementFrequency::Monthly,
        /** Chorobowe is voluntary for an entrepreneur. */
        public readonly bool $sicknessInsurance = true,
        /** Mały ZUS Plus base, which is derived from the previous year's income. */
        public readonly ?Money $malyZusPlusBase = null,
        /** Override for payers with 10+ insured persons; null uses the 1.67% statutory rate. */
        public readonly ?float $accidentRate = null,
        public readonly ContributionDeductionBasis $deductionBasis = ContributionDeductionBasis::AccruedForMonth,
        /**
         * Ryczałt health band read from the PREVIOUS year's revenue instead of
         * the current year's (an election the taxpayer may make for a whole year).
         */
        public readonly bool $healthBandFromPreviousYear = false,
        public readonly ?Money $previousYearRevenue = null,
        /**
         * Reduce the ryczałt health-band reference revenue by social
         * contributions paid (art. 81 ust. 2g). This is an election, not an
         * automatic rule, so it is a stored choice and it is printed on the
         * report that used it.
         */
        public readonly bool $reduceHealthBandBySocial = true,
        /** Cash-register letter to VAT designation, when it differs from the default. */
        public readonly array $cashRegisterLetters = [],
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->pitRegime === PitRegime::LumpSum) {
            if ($this->lumpSumRate === null) {
                throw new InvalidArgumentException(
                    'Ryczałt selected but no rate given. The rate depends on what the business '
                    .'actually does (art. 12 ustawy o ryczałcie) and cannot be inferred.',
                );
            }
            if ($this->lumpSumRate <= 0 || $this->lumpSumRate > 0.20) {
                throw new InvalidArgumentException(
                    sprintf('Ryczałt rate %.4f is outside the statutory 2%%-17%% range.', $this->lumpSumRate),
                );
            }
        }

        if ($this->zusScheme === ZusScheme::MalyZusPlus && $this->malyZusPlusBase === null) {
            throw new InvalidArgumentException(
                'Mały ZUS Plus selected but no contribution base given. The base is derived '
                .'from the previous year\'s income and must be supplied.',
            );
        }

        if ($this->healthBandFromPreviousYear && $this->previousYearRevenue === null) {
            throw new InvalidArgumentException(
                'Health band elected from the previous year\'s revenue, but that revenue was not given.',
            );
        }

        if ($this->businessStartedOnDay < 1 || $this->businessStartedOnDay > 31) {
            throw new InvalidArgumentException(
                sprintf('Business start day must be 1-31, got %d.', $this->businessStartedOnDay),
            );
        }

        if ($this->nip !== null && ! self::isValidNip($this->nip)) {
            throw new InvalidArgumentException(sprintf('NIP "%s" fails its checksum.', $this->nip));
        }
    }

    /** Polish NIP checksum (weights 6,5,7,2,3,4,5,6,7 mod 11). */
    public static function isValidNip(string $nip): bool
    {
        $digits = preg_replace('/\D/', '', $nip) ?? '';
        if (strlen($digits) !== 10) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $weights[$i] * (int) $digits[$i];
        }

        $checksum = $sum % 11;

        // A remainder of 10 can never be a check digit, so such a number is invalid.
        return $checksum !== 10 && $checksum === (int) $digits[9];
    }

    /** Months of activity completed before the given settlement month. */
    public function monthsInBusinessAt(Period $period): int
    {
        return $period->monthsSince($this->businessStartedAt);
    }

    /**
     * Whether the configured ZUS scheme is still available in the given month.
     *
     * Returned rather than auto-corrected: dropping a taxpayer off preferential
     * contributions is a decision with a deadline attached, and the report must
     * say it out loud instead of quietly changing the number.
     */
    public function zusSchemeExpiredAt(Period $period): bool
    {
        $limit = $this->zusScheme->maxMonths();
        if ($limit === null) {
            return false;
        }

        return $this->monthsInBusinessAt($period) >= $limit;
    }

    /**
     * Days covered by insurance in the settled month.
     *
     * Only the first, incomplete month is modelled here. Suspension of the
     * business and sickness periods also shorten the month, but they are events
     * this engine is not told about — so it returns the full month rather than
     * pretending to know, and the report says the figure assumes a full month.
     */
    public function insuredDaysIn(Period $period): ?int
    {
        if (! $period->equals($this->businessStartedAt) || $this->businessStartedOnDay === 1) {
            return null;
        }

        $daysInMonth = (int) $period->lastDay()->format('j');

        return max(0, $daysInMonth - $this->businessStartedOnDay + 1);
    }

    public function withZusScheme(ZusScheme $scheme, ?Money $malyZusPlusBase = null): self
    {
        return new self(
            $this->name, $this->pitRegime, $this->vatStatus, $scheme, $this->businessStartedAt,
            $this->businessStartedOnDay, $this->nip, $this->lumpSumRate, $this->vatSettlement,
            $this->sicknessInsurance, $malyZusPlusBase ?? $this->malyZusPlusBase, $this->accidentRate,
            $this->deductionBasis, $this->healthBandFromPreviousYear, $this->previousYearRevenue,
            $this->reduceHealthBandBySocial, $this->cashRegisterLetters,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'nip' => $this->nip,
            'pit_regime' => $this->pitRegime->value,
            'lump_sum_rate' => $this->lumpSumRate,
            'vat_status' => $this->vatStatus->value,
            'vat_settlement' => $this->vatSettlement->value,
            'zus_scheme' => $this->zusScheme->value,
            'sickness_insurance' => $this->sicknessInsurance,
            'business_started_at' => $this->businessStartedAt->toString(),
            'deduction_basis' => $this->deductionBasis->value,
        ];
    }
}
