<?php

declare(strict_types=1);

namespace Poland\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\FiscalSalesReport;
use Poland\Domain\Ledger;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\PurchaseRegister;
use Poland\Domain\TaxProfile;
use Poland\Reporting\AccountantReportBuilder;
use Poland\Reporting\ObligationKind;
use Poland\Reporting\PaymentStatus;
use Poland\Reporting\SettlementEngine;

final class AccountantReportTest extends TestCase
{
    private function builder(): AccountantReportBuilder
    {
        return new AccountantReportBuilder(SettlementEngine::withDefaultRates());
    }

    private function profile(
        PitRegime $regime = PitRegime::LumpSum,
        VatStatus $vat = VatStatus::ExemptBySize,
    ): TaxProfile {
        return new TaxProfile(
            name: 'Sklep',
            pitRegime: $regime,
            vatStatus: $vat,
            zusScheme: ZusScheme::Full,
            businessStartedAt: Period::of(2021, 3),
            lumpSumRate: $regime === PitRegime::LumpSum ? 0.03 : null,
        );
    }

    private function ledger(VatStatus $vat, string $gross = '18000.00', int $months = 8): Ledger
    {
        $ledger = new Ledger();
        foreach (range(1, $months) as $m) {
            $period = Period::of(2026, $m);
            $ledger->recordSales(
                $vat->settlesVat()
                    ? FiscalSalesReport::singleRate($period, $gross, '0.23')
                    : FiscalSalesReport::exempt($period, $gross),
            );
        }

        return $ledger;
    }

    public function test_the_checklist_always_has_all_three_lines(): void
    {
        // Including when one is nil: "no row" and "nothing to pay" are different
        // facts, and only one of them means the taxpayer can stop worrying.
        $report = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
        );

        self::assertCount(3, $report->obligations);
        foreach (ObligationKind::cases() as $kind) {
            self::assertNotNull($report->obligation($kind), $kind->value.' line missing');
        }
    }

    public function test_a_vat_exempt_taxpayer_gets_nothing_to_pay_not_an_unpaid_zero(): void
    {
        $report = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
        );

        $vat = $report->obligation(ObligationKind::Vat);

        self::assertSame(PaymentStatus::NothingToPay, $vat->status);
        self::assertFalse($vat->status->requiresAction());
        self::assertSame('Nic do zapłaty', $vat->headline());
    }

    public function test_a_vat_surplus_is_never_shown_as_an_amount_to_pay(): void
    {
        // The specific error this guards: input VAT exceeding output VAT is money
        // owed the other way. Rendered in an amount column it becomes a payment
        // somebody makes by mistake.
        $ledger = $this->ledger(VatStatus::Registered, '1230.00', 1);
        $ledger->recordPurchases(new PurchaseRegister(
            Period::of(2026, 1),
            Money::parse('20000.00'),
            Money::parse('4600.00'),
            5,
        ));

        $report = $this->builder()->build(
            $this->profile(vat: VatStatus::Registered),
            $ledger,
            Period::of(2026, 1),
        );

        $vat = $report->obligation(ObligationKind::Vat);

        self::assertTrue($vat->amount->isZero(), 'A surplus must not appear as an amount due.');
        self::assertTrue($vat->hasSurplus());
        self::assertSame(437000, $vat->surplus->grosze);
        self::assertStringContainsString('NADWYŻKA', $vat->headline());
        self::assertStringContainsString('nic do zapłaty', $vat->headline());
        self::assertSame(PaymentStatus::NothingToPay, $vat->status);
    }

    public function test_the_total_outstanding_excludes_what_is_already_paid(): void
    {
        $report = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
            payments: ['zus' => [
                'status' => PaymentStatus::Paid,
                'paid_at' => new \DateTimeImmutable('2026-09-15'),
                'amount_paid' => Money::parse('2757.34'),
                'reference' => 'ZUS/2026/08',
            ]],
        );

        $zus = $report->obligation(ObligationKind::Zus);
        self::assertSame(PaymentStatus::Paid, $zus->status);
        self::assertStringContainsString('Zapłacone 15.09.2026', $zus->headline());

        // Total due is unchanged; total outstanding drops by the paid amount.
        self::assertTrue($report->totalDue()->greaterThan($report->totalOutstanding()));
        self::assertSame(
            $report->totalDue()->minus($zus->amount)->grosze,
            $report->totalOutstanding()->grosze,
        );
    }

    public function test_each_obligation_names_who_it_is_paid_to_and_when(): void
    {
        $report = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
        );

        $zus = $report->obligation(ObligationKind::Zus);
        self::assertSame('Zakład Ubezpieczeń Społecznych', $zus->payTo());
        self::assertSame('2026-09-21', $zus->dueDate->format('Y-m-d'), '20.09.2026 is a Sunday');

        $pit = $report->obligation(ObligationKind::Pit);
        self::assertStringContainsString('Urząd Skarbowy', $pit->payTo());
        self::assertStringContainsString('PIT-28', $pit->form);
    }

    public function test_a_shortfall_between_due_and_paid_is_visible(): void
    {
        $report = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
            payments: ['zus' => [
                'status' => PaymentStatus::Paid,
                'amount_paid' => Money::parse('2000.00'),
            ]],
        );

        $zus = $report->obligation(ObligationKind::Zus);
        self::assertSame(75734, $zus->shortfall()->grosze, '2 757,34 due, 2 000,00 paid');
    }

    public function test_ryczalt_without_costs_has_exact_tax_but_an_unknown_profit(): void
    {
        // The distinction the financial section must not blur: the TAX is exact
        // because ryczałt is levied on revenue; the taxpayer's actual PROFIT is
        // not, because nobody recorded the costs.
        $report = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
        );

        self::assertFalse($report->report->isEstimate, 'Ryczałt tax is exact from revenue alone');
        self::assertFalse($report->financials->profitIsKnown());
        self::assertTrue($report->financials->incomeIsUpperBound());
        self::assertStringContainsString('GÓRNA GRANICA', $report->financials->caveat());
    }

    public function test_scale_without_costs_says_both_tax_and_result_are_upper_bounds(): void
    {
        $report = $this->builder()->build(
            $this->profile(PitRegime::Scale),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
        );

        self::assertTrue($report->report->isEstimate);
        self::assertStringContainsString('GÓRNE GRANICE', $report->financials->caveat());
    }

    public function test_recorded_costs_make_the_result_a_result(): void
    {
        $ledger = $this->ledger(VatStatus::ExemptBySize);
        foreach (range(1, 8) as $m) {
            $ledger->recordPurchases(new PurchaseRegister(
                Period::of(2026, $m),
                Money::parse('5000.00'),
                Money::zero(),
                4,
            ));
        }

        $report = $this->builder()->build($this->profile(), $ledger, Period::of(2026, 8));

        self::assertTrue($report->financials->profitIsKnown());
        self::assertNull($report->financials->caveat());
        self::assertSame(1300000, $report->financials->incomeMonth()->grosze, '18 000 − 5 000');
        self::assertSame(10400000, $report->financials->incomeYearToDate()->grosze, '8 × 13 000');
    }

    public function test_financials_cover_both_the_month_and_the_year_to_date(): void
    {
        $report = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
        );

        self::assertSame(1800000, $report->financials->revenueMonth->grosze);
        self::assertSame(14400000, $report->financials->revenueYearToDate->grosze, '8 × 18 000');
    }

    public function test_unverified_rates_put_a_banner_on_every_surface(): void
    {
        $report = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
        );

        self::assertNotNull($report->verificationBanner());
        self::assertStringContainsString('NIE UŻYWAĆ DO RZECZYWISTEJ ZAPŁATY', $report->verificationBanner());
        self::assertStringContainsString('NOT VERIFIED', $report->verificationBanner());
    }

    public function test_everything_paid_is_only_true_when_nothing_requires_action(): void
    {
        $paid = fn (string $ref): array => ['status' => PaymentStatus::Paid, 'reference' => $ref];

        $partly = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
            payments: ['zus' => $paid('a')],
        );
        self::assertFalse($partly->everythingPaid());
        self::assertCount(1, $partly->outstanding(), 'PIT still outstanding; VAT is nothing-to-pay');

        $all = $this->builder()->build(
            $this->profile(),
            $this->ledger(VatStatus::ExemptBySize),
            Period::of(2026, 8),
            payments: ['zus' => $paid('a'), 'pit' => $paid('b')],
        );
        self::assertTrue($all->everythingPaid());
        self::assertSame([], $all->outstanding());
    }
}
