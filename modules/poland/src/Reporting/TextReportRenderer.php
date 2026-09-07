<?php

declare(strict_types=1);

namespace Poland\Reporting;

use Poland\Domain\Money;

/**
 * Renders a monthly report as plain text.
 *
 * Order matters: the answer first ("pay this, by then"), the caveats second,
 * the derivation last. A report that buries the number under its workings does
 * not get read, and a report that shows the number without its caveats gets
 * trusted further than it should be.
 */
final class TextReportRenderer
{
    private const WIDTH = 78;

    public function render(MonthlyTaxReport $report, bool $detailed = true): string
    {
        $out = [];

        $out[] = $this->rule('=');
        $out[] = $this->centre('ROZLICZENIE MIESIĘCZNE — '.mb_strtoupper($report->period->label()));
        $out[] = $this->centre($report->profile->name.($report->profile->nip !== null ? ' · NIP '.$report->profile->nip : ''));
        $out[] = $this->rule('=');
        $out[] = '';

        $out[] = $this->pair('Forma opodatkowania', $report->profile->pitRegime->label()
            .($report->profile->lumpSumRate !== null ? sprintf(' — stawka %s%%', $this->percent($report->profile->lumpSumRate)) : ''));
        $out[] = $this->pair('Status VAT', $report->profile->vatStatus->label());
        $out[] = $this->pair('Schemat ZUS', $report->profile->zusScheme->label());
        $out[] = '';

        $out[] = $this->pair('Sprzedaż z kasy fiskalnej (brutto)', $report->grossSales->format());
        $out[] = $this->pair('Przychód do podatku dochodowego', $report->revenueForIncomeTax->format());
        $out[] = '';

        $out[] = $this->rule('-');
        $out[] = ' DO ZAPŁATY';
        $out[] = $this->rule('-');
        $out[] = $this->amount('ZUS (społeczne + zdrowotna)', $report->zus->total, $report->deadlines['zus'] ?? null);
        $out[] = $this->amount(
            $report->vat->settlesVat ? 'VAT' : 'VAT (podatnik zwolniony)',
            $report->vat->amountToPay,
            $report->deadlines['vat'] ?? null,
        );
        $out[] = $this->amount(
            $report->profile->pitRegime === \Poland\Domain\Enums\PitRegime::LumpSum ? 'Ryczałt (PIT)' : 'Zaliczka PIT',
            $report->pit->advanceDue,
            $report->deadlines['pit_advance'] ?? null,
        );
        $out[] = $this->rule('-');
        $out[] = $this->amount('RAZEM', $report->totalDue, null);
        $out[] = $this->pair('Zostaje ze sprzedaży', $report->netAfterCharges()->format()
            .($report->effectiveChargeRate() !== null
                ? sprintf('  (obciążenia = %s%% sprzedaży)', number_format($report->effectiveChargeRate() * 100, 1, ',', ''))
                : ''));
        $out[] = '';

        if ($report->isEstimate) {
            $out[] = $this->box('SZACUNEK, NIE KWOTA OSTATECZNA', [
                'Część danych potrzebnych do dokładnego wyliczenia nie została podana.',
                'Szczegóły w ostrzeżeniach poniżej.',
            ]);
            $out[] = '';
        }

        if ($report->warnings !== []) {
            $out[] = ' OSTRZEŻENIA';
            $out[] = $this->rule('-');
            foreach ($report->warnings as $warning) {
                $out[] = $this->bullet('!', $warning);
            }
            $out[] = '';
        }

        if ($report->notes !== []) {
            $out[] = ' UWAGI';
            $out[] = $this->rule('-');
            foreach ($report->notes as $note) {
                $out[] = $this->bullet('*', $note);
            }
            $out[] = '';
        }

        if ($detailed) {
            foreach ([$report->zus->breakdown, $report->vat->breakdown, $report->pit->breakdown] as $breakdown) {
                $out[] = $this->renderBreakdown($breakdown);
            }
        }

        $out[] = ' ŹRÓDŁA STAWEK';
        $out[] = $this->rule('-');
        foreach ($report->rateSources as $source) {
            $out[] = $this->bullet('-', $source);
        }
        $out[] = '';

        $out[] = $this->box('WAŻNE', [MonthlyTaxReport::DISCLAIMER]);

        return implode(PHP_EOL, $out).PHP_EOL;
    }

    private function renderBreakdown(Breakdown $breakdown): string
    {
        $out = [' '.mb_strtoupper($breakdown->title), $this->rule('-')];

        foreach ($breakdown->lines() as $line) {
            $label = ($line->emphasis ? '> ' : '  ').$line->label;
            $out[] = $this->pair($label, $line->value(), 2);

            foreach ([$line->formula, $line->legalBasis] as $detail) {
                if ($detail !== null && $detail !== '') {
                    $out[] = '      '.$this->wrap($detail, 6);
                }
            }
        }

        $out[] = '';

        return implode(PHP_EOL, $out);
    }

    private function amount(string $label, Money $value, ?array $deadline): string
    {
        $line = $this->pair('  '.$label, $value->format());

        if ($deadline !== null) {
            $line .= PHP_EOL.'      termin: '.$deadline['date']->format('d.m.Y')
                .($deadline['shifted'] ? ' (przesunięty z '.$deadline['statutory']->format('d.m.Y').')' : '')
                .($deadline['verified'] ? '' : ' [kalendarz świąt niesprawdzony]');
        }

        return $line;
    }

    private function pair(string $label, string $value, int $indent = 1): string
    {
        $pad = self::WIDTH - mb_strlen($label) - mb_strlen($value) - $indent;
        $pad = max(1, $pad);

        return str_repeat(' ', $indent).$label.str_repeat(' ', $pad).$value;
    }

    private function bullet(string $marker, string $text): string
    {
        return ' '.$marker.' '.$this->wrap($text, 3);
    }

    private function wrap(string $text, int $indent): string
    {
        $wrapped = wordwrap($text, self::WIDTH - $indent, PHP_EOL, false);

        return str_replace(PHP_EOL, PHP_EOL.str_repeat(' ', $indent), $wrapped);
    }

    /** @param list<string> $lines */
    private function box(string $title, array $lines): string
    {
        $out = [$this->rule('#'), ' '.$title, $this->rule('-')];
        foreach ($lines as $line) {
            $out[] = ' '.$this->wrap($line, 1);
        }
        $out[] = $this->rule('#');

        return implode(PHP_EOL, $out);
    }

    private function rule(string $char): string
    {
        return str_repeat($char, self::WIDTH);
    }

    private function centre(string $text): string
    {
        $pad = max(0, intdiv(self::WIDTH - mb_strlen($text), 2));

        return str_repeat(' ', $pad).$text;
    }

    private function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2, ',', ''), '0'), ',');
    }
}
