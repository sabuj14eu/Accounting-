<?php

declare(strict_types=1);

namespace Poland\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Laravel\Models\PaymentObligationModel;
use Poland\Laravel\Models\ReportVersionModel;
use Poland\Laravel\Models\SettlementModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Reporting\AccountantReport;
use Poland\Reporting\AccountantReportBuilder;
use Poland\Reporting\ObligationKind;
use Poland\Reporting\PaymentObligation;
use Poland\Reporting\PaymentStatus;
use RuntimeException;

/**
 * Generates, versions and closes the monthly accounting report, and keeps the
 * payment checklist in step with it.
 *
 * Two invariants are enforced here:
 *  - a generated report is never modified; recomputing writes a new version;
 *  - a PAID obligation is never overwritten by a recomputation.
 */
final class MonthlyReportService
{
    public function __construct(
        private readonly AccountantReportBuilder $builder,
        private readonly SettlementRecorder $recorder,
        private readonly LedgerRepository $ledgers,
        private readonly AuditRecorder $audit,
    ) {
    }

    /** Build the report for a month without storing anything. */
    public function preview(TaxProfileModel $profile, Period $period): AccountantReport
    {
        return $this->builder->build(
            $profile->toDomain(),
            $this->ledgers->forPeriod($profile, $period),
            $period,
            $this->recordedPayments($profile, $period),
            $this->latestVersionNumber($profile, $period),
            $this->isClosed($profile, $period),
        );
    }

    /**
     * Generate the report, store it as a new immutable version, and refresh the
     * payment checklist.
     */
    public function generate(TaxProfileModel $profile, Period $period, string $generatedBy = 'system'): AccountantReport
    {
        if ($this->isClosed($profile, $period)) {
            throw new RuntimeException(sprintf(
                'Miesiąc %s jest zamknięty. Aby go przeliczyć, otwórz go ponownie podając przyczynę '
                .'— zamknięty raport nie zmienia się po cichu.',
                $period->toString(),
            ));
        }

        $settlementReport = $this->recorder->settle($profile, $period);
        $settlement = SettlementModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->firstOrFail();

        $report = $this->builder->build(
            $profile->toDomain(),
            $this->ledgers->forPeriod($profile, $period),
            $period,
            $this->recordedPayments($profile, $period),
        );

        return DB::transaction(function () use ($profile, $period, $report, $settlement, $generatedBy): AccountantReport {
            $payload = json_decode(json_encode($report, JSON_THROW_ON_ERROR), true);

            $version = ($this->latestVersionNumber($profile, $period) ?? 0) + 1;

            ReportVersionModel::create([
                'tax_profile_id' => $profile->getKey(),
                'settlement_id' => $settlement->getKey(),
                'period' => $period->toString(),
                'version' => $version,
                'report' => $payload,
                'rate_provenance' => $settlement->rate_provenance ?? [],
                'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'total_due' => $report->totalDue()->jsonSerialize(),
                'is_estimate' => $report->report->isEstimate,
                'rates_fit_for_filing' => $report->report->ratesFitForFiling,
                'generated_by' => $generatedBy,
                'generated_at' => now(),
            ]);

            $this->syncObligations($profile, $period, $settlement, $report->obligations);

            $this->audit->record(
                AuditRecorder::REPORT_GENERATED,
                (int) $profile->getKey(),
                $settlement,
                $period->toString(),
                null,
                ['version' => $version, 'total_due' => $report->totalDue()->jsonSerialize()],
            );

            return new AccountantReport(
                $report->report,
                $report->obligations,
                $report->financials,
                $version,
                new \DateTimeImmutable(),
                false,
            );
        });
    }

    /**
     * Refresh the checklist, preserving anything already paid.
     *
     * @param list<PaymentObligation> $obligations
     */
    private function syncObligations(
        TaxProfileModel $profile,
        Period $period,
        SettlementModel $settlement,
        array $obligations,
    ): void {
        foreach ($obligations as $obligation) {
            $existing = PaymentObligationModel::query()
                ->where('tax_profile_id', $profile->getKey())
                ->where('period', $period->toString())
                ->where('kind', $obligation->kind->value)
                ->first();

            $attributes = [
                'settlement_id' => $settlement->getKey(),
                'form' => $obligation->form,
                'pay_to' => $obligation->payTo(),
                'amount' => $obligation->amount->jsonSerialize(),
                'surplus' => ($obligation->surplus ?? Money::zero())->jsonSerialize(),
                'due_date' => $obligation->dueDate?->format('Y-m-d'),
                'due_date_verified' => $obligation->dueDateVerified,
            ];

            if ($existing !== null && $existing->isPaid()) {
                // Keep the payment. Update only what the recomputation legitimately
                // changes, and let any difference show as a shortfall rather than
                // erasing the fact that money was sent.
                $existing->forceFill($attributes)->save();

                continue;
            }

            PaymentObligationModel::updateOrCreate(
                [
                    'tax_profile_id' => $profile->getKey(),
                    'period' => $period->toString(),
                    'kind' => $obligation->kind->value,
                ],
                $attributes + ['status' => $obligation->status->value],
            );
        }
    }

    /** Record that an obligation was actually paid. */
    public function markPaid(
        TaxProfileModel $profile,
        Period $period,
        ObligationKind $kind,
        Money $amountPaid,
        \DateTimeInterface $paidAt,
        ?string $reference = null,
        ?string $notes = null,
    ): PaymentObligationModel {
        $obligation = PaymentObligationModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->where('kind', $kind->value)
            ->firstOrFail();

        $old = ['status' => $obligation->status, 'amount' => $obligation->amount];

        $obligation->forceFill([
            'status' => PaymentStatus::Paid->value,
            'paid_at' => $paidAt,
            'amount_paid' => $amountPaid->jsonSerialize(),
            'payment_reference' => $reference,
            'notes' => $notes,
        ])->save();

        $this->audit->record(
            AuditRecorder::PAYMENT_RECORDED,
            (int) $profile->getKey(),
            $obligation,
            $period->toString(),
            $old,
            [
                'kind' => $kind->value,
                'amount_paid' => $amountPaid->jsonSerialize(),
                'reference' => $reference,
            ],
        );

        return $obligation;
    }

    /** Freeze a month. */
    public function close(TaxProfileModel $profile, Period $period): ReportVersionModel
    {
        $latest = $this->latestVersion($profile, $period);

        if ($latest === null) {
            throw new RuntimeException(sprintf(
                'Nie można zamknąć %s — nie wygenerowano jeszcze żadnego raportu za ten miesiąc.',
                $period->toString(),
            ));
        }

        $latest->forceFill(['is_closed' => true, 'closed_at' => now()])->save();

        $this->audit->record(
            AuditRecorder::PERIOD_CLOSED,
            (int) $profile->getKey(),
            $latest,
            $period->toString(),
            null,
            ['version' => $latest->version],
        );

        return $latest;
    }

    /** Reopen a closed month. Requires a reason, which is audited. */
    public function reopen(TaxProfileModel $profile, Period $period, string $reason): ReportVersionModel
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Ponowne otwarcie zamkniętego miesiąca wymaga podania przyczyny.');
        }

        $latest = $this->latestVersion($profile, $period);
        if ($latest === null || ! $latest->is_closed) {
            throw new RuntimeException(sprintf('Miesiąc %s nie jest zamknięty.', $period->toString()));
        }

        $latest->forceFill(['is_closed' => false, 'reopen_reason' => $reason])->save();

        $this->audit->record(
            AuditRecorder::PERIOD_REOPENED,
            (int) $profile->getKey(),
            $latest,
            $period->toString(),
            ['is_closed' => true],
            ['is_closed' => false, 'reason' => $reason],
        );

        return $latest;
    }

    public function isClosed(TaxProfileModel $profile, Period $period): bool
    {
        return (bool) ($this->latestVersion($profile, $period)?->is_closed ?? false);
    }

    public function latestVersion(TaxProfileModel $profile, Period $period): ?ReportVersionModel
    {
        return ReportVersionModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->orderByDesc('version')
            ->first();
    }

    private function latestVersionNumber(TaxProfileModel $profile, Period $period): ?int
    {
        return $this->latestVersion($profile, $period)?->version;
    }

    /** @return array<string,array<string,mixed>> */
    private function recordedPayments(TaxProfileModel $profile, Period $period): array
    {
        $payments = [];

        PaymentObligationModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('period', $period->toString())
            ->get()
            ->each(function (PaymentObligationModel $row) use (&$payments): void {
                if (! $row->isPaid()) {
                    return;
                }

                $payments[$row->kind] = [
                    'status' => PaymentStatus::Paid,
                    'paid_at' => $row->paid_at !== null
                        ? \DateTimeImmutable::createFromInterface($row->paid_at)
                        : null,
                    'amount_paid' => $row->amount_paid !== null
                        ? Money::parse((string) $row->amount_paid)
                        : null,
                    'reference' => $row->payment_reference,
                    'notes' => $row->notes,
                ];
            });

        return $payments;
    }
}
