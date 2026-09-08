<?php

declare(strict_types=1);

namespace Shop\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Cash\CashLedger;
use Shop\Cash\CashMovement;
use Shop\Comparison\AccountsComparison;
use Shop\Comparison\AccountsSnapshot;
use Shop\Costs\CostCategory;
use Shop\Costs\CostEntry;
use Shop\Costs\CostEntryType;
use Shop\Monitoring\ShopMonitor;
use Shop\Platforms\PlatformSettlement;
use Shop\Profit\ChannelResult;
use Shop\Profit\ProfitStatement;
use Shop\Profit\RevenueChannel;
use Shop\Reporting\LossRanking;
use Shop\Reporting\MonthlyManagementReport;
use Shop\Reporting\TextReportRenderer;
use Shop\Truth\EvidenceType;
use Shop\Truth\Figure;
use Shop\Truth\Money;
use Shop\Truth\ReviewFlag;

final class ReportAndComparisonTest extends TestCase
{
    private function statement(): ProfitStatement
    {
        return new ProfitStatement(
            '2026-08',
            [
                new Figure(Money::parse('60 000,00'), EvidenceType::FISCAL_REPORT, 'Kasa fiskalna'),
                new Figure(Money::parse('12 000,00'), EvidenceType::PLATFORM_STATEMENT, 'Glovo'),
                new Figure(Money::parse('1 500,00'), EvidenceType::USER_DECLARED, 'Sprzedaż gotówkowa poza kasą'),
            ],
            [
                CostEntry::record('FV/1', CostCategory::FOOD_AND_MATERIALS, 'Mięso', Money::parse('22 000,00'), CostEntryType::INVOICE, '2026-08'),
                CostEntry::record('REC/RENT', CostCategory::PREMISES_AND_UTILITIES, 'Czynsz', Money::parse('6 000,00'), CostEntryType::RECURRING, '2026-08'),
                CostEntry::record('FV/2', CostCategory::OTHER_OPERATING, 'Wynagrodzenia', Money::parse('14 000,00'), CostEntryType::BANK, '2026-08'),
            ],
        );
    }

    private function report(): MonthlyManagementReport
    {
        return new MonthlyManagementReport(
            $this->statement(),
            [
                new ChannelResult(RevenueChannel::DIRECT_CARD, Money::parse('40 000,00'), Money::zero(), Money::parse('12 000,00'), 1_400),
                new ChannelResult(RevenueChannel::GLOVO, Money::parse('12 000,00'), Money::parse('3 600,00'), Money::parse('3 600,00'), 380),
            ],
            [
                new PlatformSettlement(
                    'Glovo',
                    '2026-08',
                    Money::parse('12 000,00'),
                    Money::parse('3 600,00'),
                    Money::parse('120,00'),
                    new Figure(Money::parse('7 480,00'), EvidenceType::BANK_CONFIRMED, 'Glovo payout'),
                ),
            ],
            null,
            (new CashLedger('2026-08', Money::parse('500,00'), Money::parse('1 100,00'), 'Anna'))
                ->record(new CashMovement('2026-08-01', 'Utarg', Money::parse('900,00'), EvidenceType::FISCAL_REPORT))
                ->record(new CashMovement('2026-08-03', 'Wpłata', Money::parse('-200,00'), EvidenceType::BANK_CONFIRMED)),
            null,
            [],
            [],
            null,
            '2026-09-07T21:00:00',
        );
    }

    public function test_the_loss_ranking_orders_findings_by_money_at_stake(): void
    {
        $ranking = $this->report()->lossRanking();
        $ranked = $ranking->ranked();

        $this->assertNotEmpty($ranked);

        $previous = null;
        foreach ($ranked as $flag) {
            $current = $flag->financialImpact->absolute()->grosze;
            if ($previous !== null) {
                $this->assertLessThanOrEqual($previous, $current);
            }
            $previous = $current;
        }

        $this->assertStringContainsString('worth investigating', $ranking->headline());
        $this->assertStringContainsString('not a loss', $ranking->headline());
    }

    public function test_findings_that_cannot_be_priced_are_listed_not_dropped(): void
    {
        $ranking = new LossRanking([
            new ReviewFlag('STOCK_DIFFERENCE', 'stock', 'Something is off.', ['a benign reason'], ReviewFlag::SEVERITY_REVIEW, Money::parse('500,00')),
            new ReviewFlag('PROCESS_GAP', 'process', 'Something else is off.', ['another benign reason']),
        ]);

        $this->assertCount(1, $ranking->ranked());
        $this->assertCount(1, $ranking->unquantified());
        $this->assertSame('PROCESS_GAP', $ranking->unquantified()[0]->code);
    }

    public function test_notes_about_how_to_read_a_figure_are_not_ranked_as_losses(): void
    {
        $ranking = new LossRanking([
            new ReviewFlag('REAL_PROBLEM', 'stock', 'Stock is short.', ['a benign reason'], ReviewFlag::SEVERITY_REVIEW, Money::parse('500,00')),
            new ReviewFlag('READING_NOTE', 'profit', 'Part of this is not confirmed yet.', ['a benign reason'], ReviewFlag::SEVERITY_INFO, Money::parse('9 000,00')),
        ]);

        // The 9 000 note is the larger number and must NOT head the worklist.
        $this->assertCount(1, $ranking->ranked());
        $this->assertSame('REAL_PROBLEM', $ranking->ranked()[0]->code);
        $this->assertSame(Money::parse('500,00')->grosze, $ranking->totalEstimatedImpact()->grosze);
        $this->assertSame('READING_NOTE', $ranking->notes()[0]->code);
    }

    public function test_the_rendered_report_always_carries_the_management_banner(): void
    {
        $text = (new TextReportRenderer())->render($this->report());

        $this->assertStringContainsString('SHOP INTELLIGENCE', $text);
        $this->assertStringContainsString('Not an accounting record', $text);
        $this->assertStringContainsString('not a filing', $text);
        $this->assertStringContainsString('WHERE AM I LOSING MONEY?', $text);
        $this->assertStringContainsString('Possible reasons:', $text);
    }

    public function test_the_rendered_report_shows_the_certainty_split_next_to_the_numbers(): void
    {
        $text = (new TextReportRenderer())->render($this->report());

        // Costs mix a paid invoice with an unconfirmed rent: the split must show.
        $this->assertStringContainsString('mixed:', $text);
        $this->assertStringContainsString('EXPECTED 6 000,00 zł', $text);
    }

    public function test_the_comparison_with_the_official_accounts_reports_and_never_corrects(): void
    {
        $comparison = new AccountsComparison(
            $this->statement(),
            new AccountsSnapshot(
                '2026-08',
                Money::parse('68 400,00'),
                Money::parse('36 000,00'),
                'anna',
                '2026-09-05T10:00:00',
                'Monthly accountant report, exported from the accounting application by hand',
            ),
        );

        $this->assertSame(Money::parse('5 100,00')->grosze, $comparison->revenueDifference()->grosze);
        $this->assertFalse($comparison->isWithinTolerance());
        $this->assertStringContainsString('never corrected automatically', $comparison->verdict());

        $codes = array_map(static fn ($f) => $f->code, $comparison->reviewFlags());
        $this->assertContains('REVENUE_DIFFERS_FROM_ACCOUNTS', $codes);

        // §2 and §28: no method on the comparison writes anywhere.
        foreach (get_class_methods($comparison) as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(write|push|sync|update|correct|apply|post|submit|save)/i',
                $method,
                "AccountsComparison::{$method}() looks like it writes to the accounting system.",
            );
        }
    }

    public function test_an_official_snapshot_must_say_who_brought_it_across(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AccountsSnapshot('2026-08', Money::parse('1,00'), Money::zero(), '', '2026-09-05T10:00:00', '');
    }

    public function test_channels_are_ranked_by_contribution_margin(): void
    {
        $channels = $this->report()->channelsByMargin();

        $this->assertSame(RevenueChannel::DIRECT_CARD, $channels[0]->channel);
        $this->assertSame(RevenueChannel::GLOVO, $channels[1]->channel);
        $this->assertSame(70.0, $channels[0]->contributionMarginPercent());
        $this->assertSame(40.0, $channels[1]->contributionMarginPercent());
    }
    /**
     * R34 — thresholds are the caller's decision and travel with the report.
     * Pins that the monitor is injectable, not any particular value: every
     * threshold in this application is an UNVALIDATED default until set from
     * the shop's own history.
     */
    public function test_r34_the_report_runs_the_monitor_it_was_given_not_a_default_one(): void
    {
        $history = [
            '2026-05' => Money::parse('80 000,00'),
            '2026-06' => Money::parse('80 000,00'),
            '2026-07' => Money::parse('80 000,00'),
        ];

        // Revenue 73 500 is 8.1% below the median: silent at the default −15%,
        // loud when the operator has set −5% from their own history.
        $default = new MonthlyManagementReport($this->statement(), [], [], null, null, null, $history);
        $strict = new MonthlyManagementReport(
            $this->statement(), [], [], null, null, null, $history, [], null, '',
            new ShopMonitor(revenueDropPercent: -5.0),
        );

        $codes = static fn (MonthlyManagementReport $r) => array_map(static fn ($f) => $f->code, $r->reviewFlags());

        $this->assertNotContains('REVENUE_BELOW_NORMAL', $codes($default));
        $this->assertContains('REVENUE_BELOW_NORMAL', $codes($strict));
        $this->assertSame(-5.0, $strict->monitor->revenueDropPercent);
    }

    public function test_the_rendered_report_shows_confirmed_and_projected_results_side_by_side(): void
    {
        $text = (new TextReportRenderer())->render($this->report());

        $this->assertStringContainsString('CONFIRMED result', $text);
        $this->assertStringContainsString('PROJECTED result', $text);
        // 60 000 + 12 000 − 22 000 − 14 000 = 36 000 confirmed; 31 500 projected.
        $this->assertStringContainsString('CONFIRMED result   : 36 000,00 zł [ACTUAL]', $text);
        $this->assertStringContainsString('PROJECTED result   : 31 500,00 zł', $text);
    }
}
