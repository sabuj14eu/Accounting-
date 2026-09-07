<?php

declare(strict_types=1);

namespace Poland\Domain;

use InvalidArgumentException;
use Poland\Domain\Enums\VatStatus;

/**
 * A month's takings as reported by the fiscal cash register (raport miesięczny
 * z kasy fiskalnej) — the one number this taxpayer actually has.
 *
 * The report is immutable once built and keeps the raw figures the user
 * entered. Nothing downstream may rewrite them; a correction produces a new
 * report with a reason, and both survive.
 */
final class FiscalSalesReport implements \JsonSerializable
{
    /** @param list<SalesLine> $lines */
    private function __construct(
        public readonly Period $period,
        public readonly array $lines,
        public readonly ?string $registerId = null,
        public readonly ?string $reportNumber = null,
        public readonly ?string $note = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('A sales report needs at least one line.');
        }
    }

    /** @param list<SalesLine> $lines */
    public static function of(
        Period $period,
        array $lines,
        ?string $registerId = null,
        ?string $reportNumber = null,
        ?string $note = null,
    ): self {
        return new self($period, array_values($lines), $registerId, $reportNumber, $note);
    }

    /**
     * The common case: one month, one VAT rate, one gross total off the
     * register's monthly report.
     */
    public static function singleRate(
        Period $period,
        Money|string|int|float $gross,
        string $designation = '0.23',
        ?float $lumpSumRate = null,
    ): self {
        return new self($period, [
            new SalesLine($designation, $gross instanceof Money ? $gross : Money::parse($gross), $lumpSumRate),
        ]);
    }

    /** Takings of a taxpayer who does not settle VAT at all. */
    public static function exempt(Period $period, Money|string|int|float $gross, ?float $lumpSumRate = null): self
    {
        return new self($period, [
            new SalesLine('zw', $gross instanceof Money ? $gross : Money::parse($gross), $lumpSumRate),
        ]);
    }

    /**
     * Build from cash-register letters (A, B, C...) using the taxpayer's own
     * letter mapping, falling back to the regulation's defaults.
     *
     * @param array<string,Money|string|int|float> $byLetter
     * @param array<string,string> $letterMap
     */
    public static function fromRegisterLetters(
        Period $period,
        array $byLetter,
        array $letterMap,
        ?string $registerId = null,
    ): self {
        $lines = [];
        foreach ($byLetter as $letter => $gross) {
            $key = strtoupper((string) $letter);
            if (! isset($letterMap[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'Cash-register letter "%s" is not mapped to a VAT designation for this taxpayer. '
                    .'Letters C-G are assigned by the taxpayer, so the mapping must be configured, not guessed.',
                    $key,
                ));
            }
            $lines[] = new SalesLine(
                $letterMap[$key],
                $gross instanceof Money ? $gross : Money::parse($gross),
                null,
                'kasa fiskalna, litera '.$key,
            );
        }

        return new self($period, $lines, $registerId);
    }

    public function grossTotal(): Money
    {
        return Money::sum(array_map(fn (SalesLine $l): Money => $l->gross, $this->lines));
    }

    public function netTotal(): Money
    {
        return Money::sum(array_map(fn (SalesLine $l): Money => $l->net(), $this->lines));
    }

    public function vatTotal(): Money
    {
        return Money::sum(array_map(fn (SalesLine $l): Money => $l->vat(), $this->lines));
    }

    /**
     * Revenue for income-tax purposes (przychód).
     *
     * For a VAT payer this is the NET amount — VAT is never revenue. For a
     * taxpayer under an exemption there is no VAT inside the takings, so gross
     * and net are the same figure. Getting this backwards overstates a ryczałt
     * base by 23%, so it is derived from the taxpayer's VAT status and never
     * from the shape of the numbers.
     */
    public function revenueForIncomeTax(VatStatus $vatStatus): Money
    {
        return $vatStatus->settlesVat() ? $this->netTotal() : $this->grossTotal();
    }

    /**
     * Turnover counted toward the art. 113 subject-exemption limit: sales
     * excluding tax, and excluding amounts outside the scope of VAT.
     */
    public function turnoverForExemptionLimit(): Money
    {
        $relevant = array_filter($this->lines, fn (SalesLine $l): bool => $l->designation !== 'np');

        return Money::sum(array_map(fn (SalesLine $l): Money => $l->net(), $relevant));
    }

    /**
     * Revenue grouped by the ryczałt rate that applies to it.
     *
     * @return array<string,Money> keyed by the rate as a decimal string
     */
    public function revenueByLumpSumRate(VatStatus $vatStatus, float $defaultRate): array
    {
        $grouped = [];
        foreach ($this->lines as $line) {
            $rate = (string) ($line->lumpSumRate ?? $defaultRate);
            $amount = $vatStatus->settlesVat() ? $line->net() : $line->gross;
            $grouped[$rate] = isset($grouped[$rate]) ? $grouped[$rate]->plus($amount) : $amount;
        }

        return $grouped;
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period->toString(),
            'register_id' => $this->registerId,
            'report_number' => $this->reportNumber,
            'lines' => $this->lines,
            'gross_total' => $this->grossTotal(),
            'net_total' => $this->netTotal(),
            'vat_total' => $this->vatTotal(),
            'note' => $this->note,
        ];
    }
}
