<?php

declare(strict_types=1);

namespace Poland\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Poland\Domain\FiscalSalesReport;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\SalesChannel;
use Poland\Domain\SalesLine;

/**
 * Section 6 of the design: sales stay monthly and gain a channel split.
 * Nothing in the schema or the domain is keyed by day.
 */
final class MonthlySalesChannelsTest extends TestCase
{
    public function test_shop_and_glovo_lines_sum_to_the_month_and_keep_their_split(): void
    {
        $report = FiscalSalesReport::of(Period::parse('2026-08'), [
            new SalesLine('0.23', Money::parse('6000.00'), null, 'litera A'),
            new SalesLine('0.08', Money::parse('42000.00'), null, 'litera B'),
            new SalesLine('0.08', Money::parse('12000.00'), null, 'Glovo', SalesChannel::GLOVO),
        ]);

        self::assertSame('60000.00', $report->grossTotal()->jsonSerialize());
        $split = $report->grossByChannel();
        self::assertSame('48000.00', $split[SalesChannel::SHOP_REGISTER]->jsonSerialize());
        self::assertSame('12000.00', $split[SalesChannel::GLOVO]->jsonSerialize());
        self::assertCount(1, $report->linesFor(SalesChannel::GLOVO));
        self::assertArrayHasKey('gross_by_channel', $report->jsonSerialize());
    }

    public function test_a_line_defaults_to_the_shop_register_so_existing_callers_are_unchanged(): void
    {
        $line = new SalesLine('0.23', Money::parse('100.00'));

        self::assertSame(SalesChannel::SHOP_REGISTER, $line->channel);
        self::assertSame(SalesChannel::SHOP_REGISTER, $line->jsonSerialize()['channel']);
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SalesLine('0.23', Money::parse('100.00'), null, null, 'uber_moto');
    }

    public function test_the_vat_arithmetic_is_indifferent_to_the_channel(): void
    {
        $shop = new SalesLine('0.08', Money::parse('108.00'));
        $glovo = new SalesLine('0.08', Money::parse('108.00'), null, null, SalesChannel::GLOVO);

        self::assertTrue($shop->vat()->equals($glovo->vat()));
        self::assertSame('8.00', $glovo->vat()->jsonSerialize());
    }

    public function test_no_table_or_query_is_keyed_by_day(): void
    {
        // The cancelled proposal was a daily-sales schema. This pins the
        // cancellation: no migration creates anything named by day.
        $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        self::assertNotEmpty($migrations);

        foreach ($migrations as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(
                '/daily|sales_day|->date\(\'day\'\)|per_day/i',
                $source,
                basename($file).' introduces a daily grain, which the owner cancelled',
            );
        }
    }
}
