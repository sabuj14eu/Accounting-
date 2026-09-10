<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Domain\Period;
use Poland\Laravel\Models\PlatformSettlementModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Platforms\PlatformSettlement;
use Poland\Platforms\SettlementStatus;
use Poland\Platforms\VatTreatment;

/**
 * Records a platform's month and writes its sales into the monthly sales
 * report on the platform's channel — once, so the owner does not type the
 * Glovo figure twice.
 *
 * A re-entered month supersedes the earlier row (version n+1, reason
 * required); nothing is edited in place.
 */
final class PlatformSettlementService
{
    public function __construct(
        private readonly SettlementRecorder $recorder,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function record(
        TaxProfileModel $profile,
        PlatformSettlement $settlement,
        string $actor,
        ?string $correctionReason = null,
        ?int $bankTransactionId = null,
        ?int $commissionInvoiceDocumentId = null,
        ?\DateTimeInterface $statementFrom = null,
        ?\DateTimeInterface $statementTo = null,
        string $sourceType = 'manual',
        ?string $sourceFilename = null,
        ?string $sourceChecksum = null,
    ): PlatformSettlementModel {
        return DB::transaction(function () use (
            $profile, $settlement, $actor, $correctionReason, $bankTransactionId, $commissionInvoiceDocumentId,
            $statementFrom, $statementTo, $sourceType, $sourceFilename, $sourceChecksum
        ): PlatformSettlementModel {
            $existing = PlatformSettlementModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('platform', $settlement->platform->value)
                ->where('period', $settlement->period->toString())
                ->inForce()
                ->first();

            if ($existing !== null && ($correctionReason === null || trim($correctionReason) === '')) {
                throw new \RuntimeException(sprintf(
                    'Rozliczenie %s za %s jest już zapisane (wersja %d). Zmiana wymaga podania przyczyny — '
                    .'poprzedni wpis zostanie zachowany, nie nadpisany.',
                    $settlement->platform->label(),
                    $settlement->period->toString(),
                    $existing->version,
                ));
            }

            if ($existing !== null) {
                $existing->forceFill(['row_status' => PlatformSettlementModel::ROW_SUPERSEDED])->save();
            }

            $byRate = null;
            if ($settlement->grossOrdersByRate !== null) {
                $byRate = [];
                foreach ($settlement->grossOrdersByRate as $designation => $amount) {
                    $byRate[(string) $designation] = $amount->jsonSerialize();
                }
            }
            $deductions = [];
            foreach ($settlement->otherDeductions as $name => $amount) {
                $deductions[(string) $name] = $amount->jsonSerialize();
            }

            $row = PlatformSettlementModel::create([
                'tax_profile_id' => $profile->getKey(),
                'platform' => $settlement->platform->value,
                'period' => $settlement->period->toString(),
                'version' => ($existing?->version ?? 0) + 1,
                'statement_from' => $statementFrom?->format('Y-m-d'),
                'statement_to' => $statementTo?->format('Y-m-d'),
                'gross_orders' => $settlement->grossOrders->jsonSerialize(),
                'gross_orders_by_rate' => $byRate,
                'commission_net' => $settlement->commissionNet->jsonSerialize(),
                'commission_vat' => $settlement->commissionVat->jsonSerialize(),
                'other_deductions' => $deductions,
                'other_deductions_total' => $settlement->otherDeductionsTotal()->jsonSerialize(),
                'payout_expected' => $settlement->expectedPayout()->jsonSerialize(),
                'payout_received' => $settlement->payoutReceived?->jsonSerialize(),
                'bank_transaction_id' => $bankTransactionId,
                'difference' => $settlement->difference()?->jsonSerialize(),
                'vat_treatment' => $settlement->vatTreatment->value,
                'commission_invoice_document_id' => $commissionInvoiceDocumentId,
                'source_type' => $sourceType,
                'source_filename' => $sourceFilename,
                'source_checksum' => $sourceChecksum,
                'status' => $settlement->status()->value,
                'row_status' => PlatformSettlementModel::ROW_RECORDED,
                'correction_reason' => $correctionReason,
                'recorded_by' => $actor,
                'recorded_at' => now(),
            ]);

            if ($existing !== null) {
                $existing->forceFill(['superseded_by_id' => $row->getKey()])->save();
            }

            // The platform's orders are the shop's sales: written ONCE, on the
            // platform's channel, as lines of the monthly sales report.
            if ($settlement->canProduceSalesLines()) {
                $this->recorder->recordChannelLines(
                    $profile,
                    $settlement->period,
                    $settlement->salesLines(),
                    sprintf('Rozliczenie %s za %s (wersja %d)', $settlement->platform->label(), $settlement->period->toString(), $row->version),
                    'platform_settlement',
                    (int) $row->getKey(),
                );
            }

            $this->audit->record(
                AuditRecorder::PLATFORM_SETTLEMENT_RECORDED,
                (int) $profile->getKey(),
                $row,
                $settlement->period->toString(),
                $existing !== null ? ['version' => $existing->version, 'gross_orders' => $existing->gross_orders] : null,
                [
                    'platform' => $settlement->platform->value,
                    'version' => $row->version,
                    'gross_orders' => $settlement->grossOrders->jsonSerialize(),
                    'expected_payout' => $settlement->expectedPayout()->jsonSerialize(),
                    'payout_received' => $settlement->payoutReceived?->jsonSerialize(),
                    'status' => $settlement->status()->value,
                    'vat_treatment' => $settlement->vatTreatment->value,
                    'sales_lines_written' => $settlement->canProduceSalesLines(),
                    'reason' => $correctionReason,
                    'by' => $actor,
                ],
            );

            return $row;
        });
    }

    /** @return \Illuminate\Support\Collection<int,PlatformSettlementModel> */
    public function inForceFor(TaxProfileModel $profile, ?Period $period = null)
    {
        $query = PlatformSettlementModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->inForce()
            ->orderByDesc('period');

        if ($period !== null) {
            $query->where('period', $period->toString());
        }

        return $query->get();
    }

    /** @return list<\Poland\Certainty\Caveat> */
    public function caveatsFor(TaxProfileModel $profile, Period $period): array
    {
        $caveats = [];
        foreach ($this->inForceFor($profile, $period) as $row) {
            $label = \Poland\Platforms\Platform::from($row->platform)->label();
            if (VatTreatment::from($row->vat_treatment) === VatTreatment::Unknown) {
                $caveats[] = \Poland\Certainty\Caveat::platformVatTreatmentUnknown($label, $period->toString());
            }
            $status = SettlementStatus::from($row->status);
            if ($status !== SettlementStatus::Reconciled) {
                $caveats[] = \Poland\Certainty\Caveat::platformPayoutUnreconciled($label, $period->toString(), $status->label());
            }
        }

        return $caveats;
    }
}
