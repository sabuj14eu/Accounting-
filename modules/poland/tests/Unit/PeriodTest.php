<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poland\Domain\Period;

final class PeriodTest extends TestCase
{
    public function test_it_parses_and_prints(): void
    {
        self::assertSame('2026-08', Period::parse('2026-08')->toString());
        self::assertSame('2026-08', Period::parse('2026-8')->toString());
        self::assertSame('sierpień 2026', Period::of(2026, 8)->label());
    }

    public function test_it_rejects_nonsense(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Period::parse('sierpień');
    }

    public function test_it_rejects_an_impossible_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Period::of(2026, 13);
    }

    public function test_it_walks_across_year_boundaries(): void
    {
        self::assertSame('2027-01', Period::of(2026, 12)->next()->toString());
        self::assertSame('2025-12', Period::of(2026, 1)->previous()->toString());
    }

    public function test_year_to_date_covers_january_through_the_period(): void
    {
        $months = Period::of(2026, 3)->yearToDate();

        self::assertCount(3, $months);
        self::assertSame('2026-01', $months[0]->toString());
        self::assertSame('2026-03', $months[2]->toString());
    }

    public function test_month_arithmetic(): void
    {
        self::assertSame(14, Period::of(2027, 3)->monthsSince(Period::of(2026, 1)));
        self::assertSame(3, Period::of(2026, 9)->quarter());
        self::assertTrue(Period::of(2026, 9)->isQuarterEnd());
        self::assertFalse(Period::of(2026, 8)->isQuarterEnd());
    }

    public function test_last_day_handles_february(): void
    {
        self::assertSame('2026-02-28', Period::of(2026, 2)->lastDay()->format('Y-m-d'));
        self::assertSame('2028-02-29', Period::of(2028, 2)->lastDay()->format('Y-m-d'));
    }
}
