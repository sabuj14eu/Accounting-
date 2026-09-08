<?php

declare(strict_types=1);

namespace Shop\Reporting;

use Shop\Profit\ChannelResult;
use Shop\Truth\ReviewFlag;

/**
 * The monthly report as plain text — readable in a terminal, in an email, and
 * printable without a browser.
 *
 * Every figure that is not fully ACTUAL prints its state next to it, because
 * the renderer asks Total::describe() rather than formatting the amount itself.
 * That is the §25 rule holding at the last possible moment before a human reads
 * the number.
 */
final class TextReportRenderer
{
    public function render(MonthlyManagementReport $report): string
    {
        $out = [];
        $out[] = str_repeat('=', 78);
        $out[] = 'SHOP INTELLIGENCE — '.$report->period();
        $out[] = wordwrap(MonthlyManagementReport::BANNER, 78);
        if ($report->generatedAt !== '') {
            $out[] = 'Generated: '.$report->generatedAt;
        }
        $out[] = str_repeat('=', 78);
        $out[] = '';

        $statement = $report->statement;
        $out[] = '-- MONEY ------------------------------------------------------------------';
        $out[] = 'Revenue            : '.$statement->revenueTotal()->describe();
        $out[] = 'Costs              : '.$statement->costTotal()->describe();
        foreach ($report->costSections() as $label => $description) {
            $out[] = '  '.$this->pad($label, 17).': '.$description;
        }
        $out[] = 'Management profit  : '.$statement->managementProfit()->describe();
        $out[] = '';
        // Two results, side by side, both labelled — never one with a footnote.
        $out[] = 'CONFIRMED result   : '.$statement->confirmedResult()->describe();
        $out[] = 'PROJECTED result   : '.$statement->projectedResult()->describe();
        $out[] = '  (confirmed = ACTUAL evidence only; projected adds EXPECTED, ESTIMATED and USER DECLARED; '
            .'difference '.$statement->unconfirmedPortion()->format().')';
        $margin = $statement->marginPercent();
        $out[] = 'Margin             : '.($margin === null ? 'NOT CALCULABLE (no revenue recorded)'
            : sprintf('%.2f%%', $margin));
        $out[] = '';
        $out[] = ProfitStatementNote::text();
        $out[] = '';

        if ($report->channels !== []) {
            $out[] = '-- CHANNELS (contribution, before rent, wages and utilities) ---------------';
            foreach ($report->channelsByMargin() as $channel) {
                $out[] = $this->channelLine($channel);
            }
            $out[] = '';
        }

        if ($report->settlements !== []) {
            $out[] = '-- PLATFORM PAYOUTS -------------------------------------------------------';
            foreach ($report->settlements as $settlement) {
                $out[] = sprintf(
                    '%-12s %-20s expected %14s  received %14s  %s',
                    $settlement->platform,
                    $settlement->period,
                    $settlement->expectedPayout()->format(),
                    $settlement->bankReceipt?->amount->format() ?? '— none —',
                    $settlement->status(),
                );
            }
            $out[] = '';
        }

        if ($report->stock !== null) {
            $stock = $report->stock;
            $out[] = '-- STOCK ------------------------------------------------------------------';
            $out[] = 'Check is '.($stock->isComplete() ? 'COMPLETE' : 'PARTIAL — not every ingredient counted');
            $out[] = 'Lines needing review: '.count($stock->linesRequiringReview()).' of '.count($stock->lines);
            $theoretical = $stock->theoreticalFoodCostPercent();
            $actual = $stock->actualFoodCostPercent();
            if ($theoretical !== null && $actual !== null) {
                $out[] = sprintf('Food cost: theoretical %.2f%%, actual %.2f%%', $theoretical, $actual);
            }
            $out[] = '';
        }

        if ($report->cash !== null) {
            $cash = $report->cash;
            $out[] = '-- CASH -------------------------------------------------------------------';
            $out[] = 'Opening '.$cash->openingBalance->format()
                .'  expected closing '.$cash->expectedClosing()->format()
                .'  counted '.($cash->physicalCount?->format() ?? '— not counted —');
            $out[] = 'Status: '.$cash->status();
            $out[] = '';
        }

        if ($report->comparison !== null) {
            $comparison = $report->comparison;
            $out[] = '-- AGAINST THE OFFICIAL ACCOUNTS ------------------------------------------';
            $out[] = 'Revenue difference: '.$comparison->revenueDifference()->format();
            $out[] = 'Cost difference   : '.$comparison->costDifference()->format();
            $out[] = wordwrap($comparison->verdict(), 78);
            $out[] = '';
        }

        $ranking = $report->lossRanking();
        $out[] = '-- WHERE AM I LOSING MONEY? -----------------------------------------------';
        $out[] = wordwrap($ranking->headline(), 78);
        $out[] = '';
        $position = 1;
        foreach ($ranking->ranked() as $flag) {
            $out[] = $this->flagBlock($position++, $flag);
        }
        foreach ($ranking->unquantified() as $flag) {
            $out[] = $this->flagBlock(null, $flag);
        }

        if ($ranking->notes() !== []) {
            $out[] = '-- NOTES ON HOW TO READ THE FIGURES ABOVE ---------------------------------';
            foreach ($ranking->notes() as $flag) {
                $out[] = $this->flagBlock(null, $flag);
            }
        }

        $out[] = str_repeat('=', 78);

        return implode("\n", $out)."\n";
    }

    private function channelLine(ChannelResult $channel): string
    {
        $margin = $channel->contributionMarginPercent();

        return sprintf(
            '%s gross %14s  commission %12s  contribution %14s  %s',
            $this->pad($channel->channel->label(), 16),
            $channel->grossRevenue->format(),
            $channel->commission->format(),
            $channel->contribution()->format(),
            $margin === null ? 'n/a' : sprintf('%.1f%%', $margin),
        );
    }

    /** Pad to a display width, counting characters rather than bytes. */
    private function pad(string $text, int $width): string
    {
        $length = mb_strlen($text);

        return $length >= $width ? mb_substr($text, 0, $width) : $text.str_repeat(' ', $width - $length);
    }

    private function flagBlock(?int $position, ReviewFlag $flag): string
    {
        $head = $position === null ? '   [not priced]' : sprintf('%2d.', $position);
        $impact = $flag->financialImpact === null || $flag->financialImpact->isZero()
            ? ''
            : ' — up to '.$flag->financialImpact->format();

        $lines = [
            $head.' ['.$flag->severity.'] '.$flag->subject.$impact,
            '     '.wordwrap($flag->explanation, 72, "\n     "),
            '     Possible reasons:',
        ];

        foreach ($flag->possibleExplanations as $explanation) {
            $lines[] = '       · '.$explanation;
        }

        $lines[] = '';

        return implode("\n", $lines);
    }
}
