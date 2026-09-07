<?php

declare(strict_types=1);

namespace Poland\Reporting;

use Poland\Calculators\PitSettlement;
use Poland\Calculators\VatSettlement;
use Poland\Calculators\ZusSettlement;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;

/**
 * "How much do I have to pay for this month?" — the whole answer in one object.
 *
 * The report is a CALCULATION. It is not a filed return, and nothing in it may
 * be presented as one: no figure here has been submitted to ZUS, to the tax
 * office, or to KSeF, and the object says so through {@see self::DISCLAIMER}
 * and through `isEstimate` on the parts that are bounded rather than exact.
 */
final class MonthlyTaxReport implements \JsonSerializable
{
    public const DISCLAIMER =
        'To jest WYLICZENIE, nie deklaracja. Żadna z tych kwot nie została wysłana '
        .'do ZUS ani do urzędu skarbowego. Rozliczenie z księgowym pozostaje wymagane.';

    /**
     * @param array<string,array{date: \DateTimeImmutable, statutory: \DateTimeImmutable, shifted: bool, verified: bool, label: string}> $deadlines
     * @param list<string> $notes
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly TaxProfile $profile,
        public readonly Period $period,
        public readonly Money $grossSales,
        public readonly Money $revenueForIncomeTax,
        public readonly ZusSettlement $zus,
        public readonly VatSettlement $vat,
        public readonly PitSettlement $pit,
        public readonly Money $totalDue,
        public readonly array $deadlines,
        public readonly array $notes = [],
        public readonly array $warnings = [],
        public readonly bool $isEstimate = false,
        public readonly array $rateSources = [],
    ) {
    }

    /** What is left after the month's takings pay the month's public charges. */
    public function netAfterCharges(): Money
    {
        return $this->grossSales->minus($this->totalDue);
    }

    public function effectiveChargeRate(): ?float
    {
        if ($this->grossSales->isZero()) {
            return null;
        }

        return $this->totalDue->grosze / $this->grossSales->grosze;
    }

    public function jsonSerialize(): array
    {
        return [
            'disclaimer' => self::DISCLAIMER,
            'period' => $this->period->toString(),
            'profile' => $this->profile,
            'gross_sales' => $this->grossSales,
            'revenue_for_income_tax' => $this->revenueForIncomeTax,
            'summary' => [
                'zus' => $this->zus->total,
                'vat' => $this->vat->amountToPay,
                'pit' => $this->pit->advanceDue,
                'total' => $this->totalDue,
                'left_after_charges' => $this->netAfterCharges(),
            ],
            'is_estimate' => $this->isEstimate,
            'deadlines' => array_map(static fn (array $d): array => [
                'label' => $d['label'],
                'date' => $d['date']->format('Y-m-d'),
                'statutory' => $d['statutory']->format('Y-m-d'),
                'shifted' => $d['shifted'],
                'holiday_calendar_verified' => $d['verified'],
            ], $this->deadlines),
            'zus' => $this->zus,
            'vat' => $this->vat,
            'pit' => $this->pit,
            'warnings' => $this->warnings,
            'notes' => $this->notes,
            'rate_sources' => $this->rateSources,
        ];
    }
}
