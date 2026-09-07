<?php

declare(strict_types=1);

namespace Poland\Reporting;

use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Ledger;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\TaxProfile;

/**
 * Turns a settled month into the report an accountant reads and the checklist a
 * taxpayer works through.
 *
 * Framework-free, so the same object backs the web page, the printed sheet, the
 * PDF and the standalone CLI. A figure must never depend on which surface asked
 * for it.
 */
final class AccountantReportBuilder
{
    public function __construct(private readonly SettlementEngine $engine) {}

    /**
     * @param array<string,array{status: PaymentStatus, paid_at?: \DateTimeImmutable, amount_paid?: Money, reference?: string, notes?: string}> $payments
     *        Recorded payments keyed by obligation kind, when known.
     */
    public function build(
        TaxProfile $profile,
        Ledger $ledger,
        Period $period,
        array $payments = [],
        ?int $version = null,
        bool $isClosed = false,
    ): AccountantReport {
        $report = $this->engine->settle($profile, $ledger, $period);

        return new AccountantReport(
            $report,
            $this->obligations($report, $payments),
            $this->financials($profile, $ledger, $period, $report),
            $version,
            new \DateTimeImmutable(),
            $isClosed,
        );
    }

    /**
     * @param array<string,array<string,mixed>> $payments
     * @return list<PaymentObligation>
     */
    public function obligations(MonthlyTaxReport $report, array $payments = []): array
    {
        $obligations = [];

        foreach (ObligationKind::cases() as $kind) {
            [$amount, $surplus, $form] = $this->amountFor($kind, $report);

            $deadline = $report->deadlines[$kind->deadlineKey()] ?? null;
            $recorded = $payments[$kind->value] ?? null;

            // A surplus or a genuine zero is NOTHING TO PAY — a distinct state,
            // never a zero-amount "unpaid" row.
            $status = match (true) {
                $recorded !== null && ($recorded['status'] ?? null) === PaymentStatus::Paid => PaymentStatus::Paid,
                $amount->isZero() => PaymentStatus::NothingToPay,
                default => PaymentStatus::Unpaid,
            };

            $obligations[] = new PaymentObligation(
                kind: $kind,
                period: $report->period,
                amount: $amount,
                status: $status,
                dueDate: $deadline !== null ? \DateTimeImmutable::createFromInterface($deadline['date']) : null,
                dueDateVerified: $deadline === null ? false : (bool) $deadline['verified'],
                form: $form,
                surplus: $surplus,
                paidAt: $recorded['paid_at'] ?? null,
                amountPaid: $recorded['amount_paid'] ?? null,
                paymentReference: $recorded['reference'] ?? null,
                notes: $recorded['notes'] ?? null,
            );
        }

        return $obligations;
    }

    /** @return array{0: Money, 1: Money|null, 2: string|null} amount, surplus, form */
    private function amountFor(ObligationKind $kind, MonthlyTaxReport $report): array
    {
        return match ($kind) {
            ObligationKind::Zus => [
                $report->zus->total,
                null,
                'DRA',
            ],
            ObligationKind::Pit => [
                $report->pit->advanceDue,
                null,
                $this->pitForm($report->profile->pitRegime),
            ],
            ObligationKind::Vat => [
                $report->vat->amountToPay,
                // Carried separately so it can never reach an amount column.
                $report->vat->carryForward->isPositive() ? $report->vat->carryForward : null,
                $report->vat->settlesVat ? $report->vat->jpkStructure : null,
            ],
        };
    }

    private function pitForm(PitRegime $regime): string
    {
        return match ($regime) {
            PitRegime::LumpSum => 'Ryczałt (roczne: PIT-28)',
            PitRegime::Flat => 'Zaliczka PIT liniowy (roczne: PIT-36L)',
            PitRegime::Scale => 'Zaliczka PIT skala (roczne: PIT-36)',
        };
    }

    private function financials(
        TaxProfile $profile,
        Ledger $ledger,
        Period $period,
        MonthlyTaxReport $report,
    ): FinancialResult {
        $revenueYtd = Money::zero();
        $costsYtd = Money::zero();

        foreach ($period->yearToDate() as $month) {
            $sales = $ledger->salesFor($month);
            if ($sales !== null) {
                $revenueYtd = $revenueYtd->plus($sales->revenueForIncomeTax($profile->vatStatus));
            }
            $costsYtd = $costsYtd->plus(
                $ledger->purchasesFor($month)?->deductibleCostsNet ?? Money::zero(),
            );
        }

        return new FinancialResult(
            period: $period,
            revenueMonth: $report->revenueForIncomeTax,
            costsMonth: $ledger->purchasesFor($period)?->deductibleCostsNet ?? Money::zero(),
            revenueYearToDate: $revenueYtd,
            costsYearToDate: $costsYtd,
            costsRecorded: $ledger->hasAnyPurchases(),
            taxedOnRevenue: $profile->pitRegime === PitRegime::LumpSum,
        );
    }
}
