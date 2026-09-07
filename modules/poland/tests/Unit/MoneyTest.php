<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poland\Domain\Money;

final class MoneyTest extends TestCase
{
    public function test_it_parses_polish_and_english_formats(): void
    {
        self::assertSame(4850000, Money::parse('48 500,00')->grosze);
        self::assertSame(4850000, Money::parse('48500.00')->grosze);
        self::assertSame(4850000, Money::parse('48,500.00')->grosze);
        self::assertSame(4850000, Money::parse("48\u{00A0}500,00 zł")->grosze);
        self::assertSame(4850000, Money::parse(48500)->grosze);
        self::assertSame(123456, Money::parse('1234,56')->grosze);
    }

    public function test_it_reads_a_three_digit_group_after_a_comma_as_thousands(): void
    {
        // "1,234" is 1234 złoty, not 1 złoty 23 grosze. Reading it the other
        // way understates a cash-register total by a factor of a thousand.
        self::assertSame(123400, Money::parse('1,234')->grosze);
        self::assertSame(123450, Money::parse('1,234.50')->grosze);
        // But a one or two digit tail is a decimal comma.
        self::assertSame(150, Money::parse('1,5')->grosze);
        self::assertSame(123, Money::parse('1,23')->grosze);
    }

    public function test_it_refuses_unreadable_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('czterdzieści tysięcy');
    }

    public function test_rounding_to_zloty_follows_ordynacja_podatkowa(): void
    {
        // Below 50 gr down, 50 gr and above up.
        self::assertSame(10000, Money::parse('100.49')->roundedToZloty()->grosze);
        self::assertSame(10100, Money::parse('100.50')->roundedToZloty()->grosze);
        self::assertSame(10100, Money::parse('100.51')->roundedToZloty()->grosze);
        self::assertSame(10000, Money::parse('100.00')->roundedToZloty()->grosze);
    }

    public function test_multiplication_rounds_half_up_on_grosze(): void
    {
        // 5 652 x 2,45% = 138,474 -> 138,47
        self::assertSame(13847, Money::parse('5652.00')->times(0.0245)->grosze);
        // 1 399,80 x 2,45% = 34,2951 -> 34,30 (this single groszy is the
        // difference between the published 442,90 and a wrong 442,89).
        self::assertSame(3430, Money::parse('1399.80')->times(0.0245)->grosze);
    }

    public function test_it_never_loses_grosze_across_a_sum(): void
    {
        $parts = array_map(static fn (int $i): Money => Money::parse('0.01'), range(1, 100));
        self::assertSame(100, Money::sum($parts)->grosze);
    }

    public function test_clamp_and_comparisons(): void
    {
        self::assertTrue(Money::parse('-5.00')->clampAtZero()->isZero());
        self::assertTrue(Money::parse('5.00')->greaterThan(Money::parse('4.99')));
        self::assertTrue(Money::parse('4.99')->lessThan(Money::parse('5.00')));
        self::assertSame(500, Money::max(Money::parse('5.00'), Money::parse('1.00'))->grosze);
        self::assertSame(100, Money::min(Money::parse('5.00'), Money::parse('1.00'))->grosze);
    }
}
