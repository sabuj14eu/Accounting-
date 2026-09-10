<?php

declare(strict_types=1);

namespace Poland\Platforms;

use InvalidArgumentException;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\SalesLine;

/**
 * One platform's month as an ACCOUNTING source.
 *
 *     gross orders − commission (gross) − named deductions = expected payout
 *
 * against what the bank received. The gross orders are the shop's sales for
 * the month (the platform acts as intermediary under the usual contract); the
 * commission is the shop's cost. How the commission enters VAT is a decision
 * ({@see VatTreatment}) this object refuses to make.
 */
final class PlatformSettlement implements \JsonSerializable
{
    /**
     * @param array<string,Money>|null $grossOrdersByRate designation ("0.08") => gross; null when the statement gives no split
     * @param array<string,Money> $otherDeductions each deduction named
     */
    public function __construct(
        public readonly Platform $platform,
        public readonly Period $period,
        public readonly Money $grossOrders,
        public readonly ?array $grossOrdersByRate,
        public readonly Money $commissionNet,
        public readonly Money $commissionVat,
        public readonly array $otherDeductions,
        public readonly VatTreatment $vatTreatment,
        public readonly ?Money $payoutReceived = null,
        ?Money $tolerance = null,
    ) {
        $this->tolerance = $tolerance ?? Money::zero();

        foreach (['gross orders' => $grossOrders, 'commission net' => $commissionNet, 'commission VAT' => $commissionVat] as $name => $amount) {
            if ($amount->isNegative()) {
                throw new InvalidArgumentException(sprintf(
                    'Platform %s is recorded as a positive magnitude; the subtraction happens here, not in the caller.',
                    $name,
                ));
            }
        }
        foreach ($otherDeductions as $name => $amount) {
            if (! is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Every deduction must be named. An unnamed deduction cannot be explained.');
            }
            if (! $amount instanceof Money || $amount->isNegative()) {
                throw new InvalidArgumentException(sprintf('Deduction "%s" must be a positive Money.', $name));
            }
        }
        if ($this->tolerance->isNegative()) {
            throw new InvalidArgumentException('Tolerance cannot be negative.');
        }

        if ($grossOrdersByRate !== null) {
            $sum = Money::sum(array_values($grossOrdersByRate));
            if (! $sum->equals($grossOrders)) {
                throw new InvalidArgumentException(sprintf(
                    'The per-rate split (%s) does not add up to gross orders (%s).',
                    $sum->format(),
                    $grossOrders->format(),
                ));
            }
            foreach ($grossOrdersByRate as $designation => $amount) {
                if (! SalesLine::isKnownDesignation((string) $designation)) {
                    throw new InvalidArgumentException(sprintf('Unknown VAT designation "%s" in the platform split.', $designation));
                }
            }
        }
    }

    public readonly Money $tolerance;

    public function commissionGross(): Money
    {
        return $this->commissionNet->plus($this->commissionVat);
    }

    public function otherDeductionsTotal(): Money
    {
        return Money::sum(array_values($this->otherDeductions));
    }

    public function expectedPayout(): Money
    {
        return $this->grossOrders->minus($this->commissionGross())->minus($this->otherDeductionsTotal());
    }

    /** Positive = the bank received LESS than the platform's own figures imply. Null without a receipt. */
    public function difference(): ?Money
    {
        if ($this->payoutReceived === null) {
            return null;
        }

        return $this->expectedPayout()->minus($this->payoutReceived);
    }

    public function status(): SettlementStatus
    {
        $difference = $this->difference();
        if ($difference === null) {
            return SettlementStatus::NoPayoutRecorded;
        }

        $absolute = $difference->isNegative() ? Money::zero()->minus($difference) : $difference;

        return $absolute->greaterThan($this->tolerance)
            ? SettlementStatus::RequiresReview
            : SettlementStatus::Reconciled;
    }

    public function effectiveCommissionRate(): ?float
    {
        if ($this->grossOrders->isZero()) {
            return null;
        }

        return $this->commissionGross()->plus($this->otherDeductionsTotal())->grosze / $this->grossOrders->grosze;
    }

    /** Fields the statement did not supply and the month cannot be closed without. */
    public function missingFields(): array
    {
        $missing = [];
        if ($this->grossOrdersByRate === null) {
            $missing[] = 'gross_orders_by_rate';
        }
        if (! $this->vatTreatment->isDecided()) {
            $missing[] = 'vat_treatment';
        }

        return $missing;
    }

    public function canProduceSalesLines(): bool
    {
        return $this->grossOrdersByRate !== null;
    }

    /**
     * The month's platform sales as lines of the monthly sales report, one per
     * VAT rate, on the platform's channel. Refuses without a rate split: the
     * system does not assume a rate for the whole payout.
     *
     * @return list<SalesLine>
     */
    public function salesLines(): array
    {
        if ($this->grossOrdersByRate === null) {
            throw new \RuntimeException(sprintf(
                'Rozliczenie %s za %s nie podaje podziału sprzedaży na stawki VAT (MISSING_FIELD). '
                .'Bez niego nie da się zapisać sprzedaży z platformy — system nie zakłada stawki dla %s.',
                $this->platform->label(),
                $this->period->toString(),
                $this->grossOrders->format(),
            ));
        }

        $lines = [];
        foreach ($this->grossOrdersByRate as $designation => $gross) {
            if ($gross->isZero()) {
                continue;
            }
            $lines[] = new SalesLine(
                (string) $designation,
                $gross,
                null,
                sprintf('%s — rozliczenie za %s', $this->platform->label(), $this->period->toString()),
                $this->platform->salesChannel(),
            );
        }

        return $lines;
    }

    /**
     * What this settlement contributes to the VAT return on its own.
     *
     * Domestic invoice: nothing here — the commission's input VAT comes from
     * the posted KSeF invoice, and adding it again would double it.
     * Import of services: the self-assessed output VAT and, where entitled,
     * the same amount as input VAT.
     *
     * @return array{output_vat: Money, input_vat: Money, note: string}
     */
    public function vatRows(): array
    {
        $this->vatTreatment->assertPostable(sprintf('%s %s', $this->platform->label(), $this->period->toString()));

        return match ($this->vatTreatment) {
            VatTreatment::DomesticInvoice => [
                'output_vat' => Money::zero(),
                'input_vat' => Money::zero(),
                'note' => 'VAT naliczony z prowizji pochodzi z faktury dostawcy w KSeF (zaksięgowanej osobno) — tutaj nic, żeby nie liczyć podwójnie.',
            ],
            VatTreatment::ImportOfServices => [
                'output_vat' => $this->commissionVat,
                'input_vat' => $this->commissionVat,
                'note' => 'Import usług: VAT należny naliczony przez nabywcę od prowizji netto i odliczony jako naliczony w tym samym okresie.',
            ],
        };
    }

    /**
     * Why the bank might differ from the platform's figures — listed before
     * anybody reaches for a worse explanation.
     *
     * @return list<string>
     */
    public function reviewExplanations(): array
    {
        return match ($this->status()) {
            SettlementStatus::NoPayoutRecorded => [
                'wypłata jest jeszcze w normalnym terminie rozliczenia platformy',
                'wyciąg obejmujący datę wypłaty nie został jeszcze zaimportowany',
                'wypłata trafiła na inne konto niż zaimportowane',
                'platforma wstrzymała wypłatę na poczet wcześniejszego zwrotu',
            ],
            SettlementStatus::RequiresReview => [
                'zwroty lub reklamacje klientów rozliczone w tej wypłacie, a raportowane w innym okresie',
                'opłata marketingowa lub promocja potrącona poza linią prowizji',
                'zamówienia z ostatniego dnia okresu wypłacone w następnym',
                'korekta za poprzedni okres potrącona z tej wypłaty',
                'stawka prowizji zmieniła się w trakcie okresu, a zestawienie używa starej',
            ],
            SettlementStatus::Reconciled => [],
        };
    }

    public function jsonSerialize(): array
    {
        return [
            'platform' => $this->platform->value,
            'period' => $this->period->toString(),
            'gross_orders' => $this->grossOrders,
            'gross_orders_by_rate' => $this->grossOrdersByRate,
            'commission_net' => $this->commissionNet,
            'commission_vat' => $this->commissionVat,
            'commission_gross' => $this->commissionGross(),
            'other_deductions' => $this->otherDeductions,
            'other_deductions_total' => $this->otherDeductionsTotal(),
            'expected_payout' => $this->expectedPayout(),
            'payout_received' => $this->payoutReceived,
            'difference' => $this->difference(),
            'status' => $this->status()->value,
            'vat_treatment' => $this->vatTreatment->value,
            'effective_commission_rate' => $this->effectiveCommissionRate(),
            'missing_fields' => $this->missingFields(),
            'review_explanations' => $this->reviewExplanations(),
        ];
    }
}
