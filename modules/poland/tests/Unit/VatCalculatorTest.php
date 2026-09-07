<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poland\Calculators\VatCalculator;
use Poland\Domain\Enums\PitRegime;
use Poland\Domain\Enums\VatStatus;
use Poland\Domain\Enums\ZusScheme;
use Poland\Domain\FiscalSalesReport;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\PurchaseRegister;
use Poland\Domain\SalesLine;
use Poland\Domain\TaxProfile;
use Poland\Rates\RateRepository;

final class VatCalculatorTest extends TestCase
{
    private VatCalculator $vat;

    protected function setUp(): void
    {
        $this->vat = new VatCalculator(RateRepository::default());
    }

    private function profile(VatStatus $status = VatStatus::Registered): TaxProfile
    {
        return new TaxProfile(
            name: 'Test',
            pitRegime: PitRegime::LumpSum,
            vatStatus: $status,
            zusScheme: ZusScheme::Full,
            businessStartedAt: Period::of(2019, 1),
            lumpSumRate: 0.085,
        );
    }

    public function test_output_vat_is_extracted_from_a_gross_cash_register_total(): void
    {
        $sales = FiscalSalesReport::singleRate(Period::of(2026, 8), '40000.00', '0.23');
        $result = $this->vat->calculate($this->profile(), Period::of(2026, 8), $sales);

        // 40 000 x 23 / 123 = 7 479,67
        self::assertSame(747967, $result->outputVat->grosze);
        // Rounded to full złoty for payment.
        self::assertSame(748000, $result->amountToPay->grosze);
    }

    public function test_mixed_rates_are_computed_per_line(): void
    {
        $sales = FiscalSalesReport::of(Period::of(2026, 8), [
            new SalesLine('0.23', Money::parse('12300.00')),
            new SalesLine('0.08', Money::parse('10800.00')),
            new SalesLine('0.05', Money::parse('10500.00')),
            new SalesLine('zw', Money::parse('5000.00')),
        ]);

        $result = $this->vat->calculate($this->profile(), Period::of(2026, 8), $sales);

        // 2 300 + 800 + 500 + 0
        self::assertSame(360000, $result->outputVat->grosze);
    }

    public function test_input_vat_reduces_the_amount_payable(): void
    {
        $sales = FiscalSalesReport::singleRate(Period::of(2026, 8), '40000.00', '0.23');
        $purchases = new PurchaseRegister(
            Period::of(2026, 8),
            Money::parse('10000.00'),
            Money::parse('2300.00'),
            12,
        );

        $result = $this->vat->calculate($this->profile(), Period::of(2026, 8), $sales, $purchases);

        self::assertSame(230000, $result->inputVat->grosze);
        self::assertSame(518000, $result->amountToPay->grosze);
    }

    public function test_an_input_surplus_becomes_a_carry_forward_not_a_negative_payment(): void
    {
        $sales = FiscalSalesReport::singleRate(Period::of(2026, 8), '1230.00', '0.23');
        $purchases = new PurchaseRegister(Period::of(2026, 8), Money::parse('20000.00'), Money::parse('4600.00'));

        $result = $this->vat->calculate($this->profile(), Period::of(2026, 8), $sales, $purchases);

        self::assertTrue($result->amountToPay->isZero());
        self::assertSame(437000, $result->carryForward->grosze);
    }

    public function test_without_a_purchase_register_the_figure_is_labelled_an_upper_bound(): void
    {
        $sales = FiscalSalesReport::singleRate(Period::of(2026, 8), '40000.00', '0.23');
        $result = $this->vat->calculate($this->profile(), Period::of(2026, 8), $sales);

        self::assertNotEmpty(array_filter(
            $result->notes,
            static fn (string $n): bool => str_contains($n, 'GÓRNA GRANICA'),
        ));
    }

    public function test_an_exempt_taxpayer_owes_nothing_and_files_nothing(): void
    {
        $sales = FiscalSalesReport::exempt(Period::of(2026, 8), '40000.00');
        $result = $this->vat->calculate($this->profile(VatStatus::ExemptBySize), Period::of(2026, 8), $sales);

        self::assertFalse($result->settlesVat);
        self::assertTrue($result->amountToPay->isZero());
        self::assertNull($result->jpkStructure);
    }

    public function test_it_warns_before_the_exemption_limit_is_reached(): void
    {
        $sales = FiscalSalesReport::exempt(Period::of(2026, 8), '20000.00');
        $result = $this->vat->calculate(
            $this->profile(VatStatus::ExemptBySize),
            Period::of(2026, 8),
            $sales,
            null,
            null,
            Money::parse('200000.00'),
        );

        self::assertNotEmpty(array_filter(
            $result->notes,
            static fn (string $n): bool => str_contains($n, 'UWAGA') && str_contains($n, 'limitu'),
        ));
    }

    public function test_it_reports_a_breached_exemption_limit_as_a_status_change(): void
    {
        $sales = FiscalSalesReport::exempt(Period::of(2026, 8), '20000.00');
        $result = $this->vat->calculate(
            $this->profile(VatStatus::ExemptBySize),
            Period::of(2026, 8),
            $sales,
            null,
            null,
            Money::parse('250000.00'),
        );

        self::assertNotEmpty(array_filter(
            $result->notes,
            static fn (string $n): bool => str_contains($n, 'LIMIT PRZEKROCZONY'),
        ));
    }

    public function test_unknown_year_to_date_turnover_is_reported_as_unchecked_not_as_safe(): void
    {
        $sales = FiscalSalesReport::exempt(Period::of(2026, 8), '20000.00');
        $result = $this->vat->calculate($this->profile(VatStatus::ExemptBySize), Period::of(2026, 8), $sales);

        self::assertNotEmpty(array_filter(
            $result->notes,
            static fn (string $n): bool => str_contains($n, 'NIE została sprawdzona'),
        ));
    }

    public function test_amounts_outside_the_scope_of_vat_do_not_count_toward_the_limit(): void
    {
        $sales = FiscalSalesReport::of(Period::of(2026, 8), [
            new SalesLine('zw', Money::parse('10000.00')),
            new SalesLine('np', Money::parse('5000.00')),
        ]);

        self::assertSame(1000000, $sales->turnoverForExemptionLimit()->grosze);
    }
}
