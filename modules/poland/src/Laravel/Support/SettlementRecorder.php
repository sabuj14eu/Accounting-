<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\SalesReportModel;
use Poland\Laravel\Models\SettlementModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Reporting\MonthlyTaxReport;
use Poland\Reporting\SettlementEngine;
use RuntimeException;

/**
 * Records cash-register takings and stores the settlements computed from them.
 *
 * Two invariants are enforced here rather than left to callers:
 *  - a month's takings are recorded once, and a change is a CORRECTION that
 *    supersedes the previous report instead of overwriting it;
 *  - a settlement is a calculation until an actual submission succeeds, and
 *    only {@see self::markFiled()} may say otherwise.
 */
final class SettlementRecorder
{
    public function __construct(
        private readonly SettlementEngine $engine,
        private readonly LedgerRepository $ledgers,
        private readonly AuditRecorder $audit,
    ) {
    }

    /**
     * Record a month's takings from the fiscal cash register.
     *
     * @param array<string,Money|string> $grossByDesignation
     */
    public function recordSales(
        TaxProfileModel $profile,
        Period $period,
        array $grossByDesignation,
        ?string $registerId = null,
        ?string $reportNumber = null,
        ?string $correctionReason = null,
    ): SalesReportModel {
        return DB::transaction(function () use (
            $profile, $period, $grossByDesignation, $registerId, $reportNumber, $correctionReason
        ): SalesReportModel {
            $existing = SalesReportModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('period', $period->toString())
                ->inForce()
                ->first();

            if ($existing !== null && $correctionReason === null) {
                throw new RuntimeException(sprintf(
                    'Sprzedaż za %s została już zapisana (%s). Zmiana wymaga podania przyczyny '
                    .'korekty — poprzedni raport zostanie zachowany, nie nadpisany.',
                    $period->toString(),
                    Money::parse((string) $existing->gross_total)->format(),
                ));
            }

            $lines = [];
            $gross = Money::zero();
            $net = Money::zero();
            $vat = Money::zero();

            foreach ($grossByDesignation as $designation => $amount) {
                $line = new \Poland\Domain\SalesLine(
                    (string) $designation,
                    $amount instanceof Money ? $amount : Money::parse($amount),
                );
                $lines[] = $line;
                $gross = $gross->plus($line->gross);
                $net = $net->plus($line->net());
                $vat = $vat->plus($line->vat());
            }

            if ($lines === []) {
                throw new RuntimeException('Raport sprzedaży musi zawierać co najmniej jedną pozycję.');
            }

            $report = SalesReportModel::create([
                'tax_profile_id' => $profile->getKey(),
                'period' => $period->toString(),
                'register_id' => $registerId,
                'report_number' => $reportNumber,
                'gross_total' => $gross->jsonSerialize(),
                'net_total' => $net->jsonSerialize(),
                'vat_total' => $vat->jsonSerialize(),
                'status' => SalesReportModel::STATUS_RECORDED,
                'correction_reason' => $correctionReason,
            ]);

            foreach ($lines as $line) {
                $report->lines()->create([
                    'designation' => $line->designation,
                    'gross' => $line->gross->jsonSerialize(),
                    'net' => $line->net()->jsonSerialize(),
                    'vat' => $line->vat()->jsonSerialize(),
                    'lump_sum_rate' => $line->lumpSumRate,
                    'note' => $line->note,
                ]);
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'status' => SalesReportModel::STATUS_SUPERSEDED,
                    'superseded_by_id' => $report->getKey(),
                ])->save();
            }

            $this->audit->record(
                $existing !== null ? AuditRecorder::SALES_CORRECTED : AuditRecorder::SALES_RECORDED,
                (int) $profile->getKey(),
                $report,
                $period->toString(),
                $existing !== null ? ['gross_total' => $existing->gross_total] : null,
                ['gross_total' => $gross->jsonSerialize(), 'reason' => $correctionReason],
            );

            return $report;
        });
    }

    /** Compute a month and store the result, without claiming it was filed. */
    public function settle(TaxProfileModel $profile, Period $period): MonthlyTaxReport
    {
        $ledger = $this->ledgers->forPeriod($profile, $period);
        $report = $this->engine->settle($profile->toDomain(), $ledger, $period);

        $settlement = SettlementModel::updateOrCreate(
            ['tax_profile_id' => $profile->getKey(), 'period' => $period->toString()],
            [
                'gross_sales' => $report->grossSales->jsonSerialize(),
                'revenue_for_income_tax' => $report->revenueForIncomeTax->jsonSerialize(),
                'zus_total' => $report->zus->total->jsonSerialize(),
                'zus_social' => $report->zus->socialTotal->jsonSerialize(),
                'zus_health' => $report->zus->health->jsonSerialize(),
                'vat_due' => $report->vat->amountToPay->jsonSerialize(),
                'pit_due' => $report->pit->advanceDue->jsonSerialize(),
                'total_due' => $report->totalDue->jsonSerialize(),
                'is_estimate' => $report->isEstimate,
                'report' => json_decode(json_encode($report, JSON_THROW_ON_ERROR), true),
                'rate_sources' => $report->rateSources,
                'computed_at' => now(),
            ],
        );

        $this->audit->record(
            AuditRecorder::SETTLEMENT_COMPUTED,
            (int) $profile->getKey(),
            $settlement,
            $period->toString(),
            null,
            [
                'zus' => $report->zus->total->jsonSerialize(),
                'vat' => $report->vat->amountToPay->jsonSerialize(),
                'pit' => $report->pit->advanceDue->jsonSerialize(),
                'total' => $report->totalDue->jsonSerialize(),
                'is_estimate' => $report->isEstimate,
            ],
        );

        return $report;
    }

    /**
     * Record that a settlement really was submitted.
     *
     * Requires a channel and a reference from the receiving system, because a
     * submission nobody can point at is not a submission.
     */
    public function markFiled(
        TaxProfileModel $profile,
        Period $period,
        string $channel,
        string $reference,
    ): SettlementModel {
        if (trim($reference) === '') {
            throw new RuntimeException(
                'Nie można oznaczyć rozliczenia jako złożonego bez numeru referencyjnego '
                .'zwróconego przez system odbierający.',
            );
        }

        $settlement = SettlementModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->firstOrFail();

        $settlement->forceFill([
            'filed_at' => now(),
            'filing_channel' => $channel,
            'filing_reference' => $reference,
        ])->save();

        $this->audit->record(
            AuditRecorder::SETTLEMENT_FILED,
            (int) $profile->getKey(),
            $settlement,
            $period->toString(),
            null,
            ['channel' => $channel, 'reference' => $reference],
        );

        return $settlement;
    }
}
