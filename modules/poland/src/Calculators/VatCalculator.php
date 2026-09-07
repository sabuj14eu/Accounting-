<?php

declare(strict_types=1);

namespace Poland\Calculators;

use Poland\Domain\FiscalSalesReport;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\PurchaseRegister;
use Poland\Domain\TaxProfile;
use Poland\Rates\RateRepository;
use Poland\Reporting\Breakdown;

/**
 * VAT due for a settlement period.
 *
 * The honest limit of a cash-register-only workflow lives here: output VAT is
 * exact from the register, but VAT PAYABLE is output minus input, and input VAT
 * comes from purchase invoices the taxpayer has not supplied. When no purchase
 * register is present the result is labelled an UPPER BOUND rather than quietly
 * presenting output VAT as the amount to pay.
 */
final class VatCalculator
{
    public function __construct(private readonly RateRepository $rates) {}

    public function calculate(
        TaxProfile $profile,
        Period $period,
        FiscalSalesReport $sales,
        ?PurchaseRegister $purchases = null,
        ?Money $carryForwardIn = null,
        ?Money $turnoverYearToDate = null,
    ): VatSettlement {
        $version = $this->rates->vat()->for($period);
        $breakdown = new Breakdown('VAT — '.$period->label());
        $notes = [];
        $carryForwardIn ??= Money::zero();

        if (! $profile->vatStatus->settlesVat()) {
            return $this->exemptSettlement($profile, $period, $sales, $version, $breakdown, $turnoverYearToDate);
        }

        $breakdown->addText('Status', $profile->vatStatus->label());

        $outputVat = Money::zero();
        foreach ($sales->lines as $line) {
            $label = $line->isTaxable()
                ? sprintf('Sprzedaż %s%%', rtrim(rtrim(number_format($line->rate() * 100, 2, ',', ''), '0'), ','))
                : sprintf('Sprzedaż "%s"', $line->designation);

            $breakdown->add(
                $label.' — brutto',
                $line->gross,
                $line->note,
            );

            if ($line->isTaxable()) {
                $vat = $line->vat();
                $outputVat = $outputVat->plus($vat);
                $breakdown->add(
                    $label.' — VAT należny',
                    $vat,
                    sprintf('%s × %s / (1 + %s)', $line->gross->format(''), $line->rate(), $line->rate()),
                    'Ustawa o VAT, art. 29a — podstawą jest kwota należna pomniejszona o podatek',
                );
            }
        }

        $breakdown->addTotal('VAT należny (sprzedaż)', $outputVat, null, 'Ustawa o VAT, art. 41');

        $inputVat = $purchases?->deductibleInputVat ?? Money::zero();
        if ($purchases === null) {
            $notes[] = 'BRAK REJESTRU ZAKUPÓW: podano wyłącznie sprzedaż z kasy fiskalnej. '
                .'Kwota poniżej to GÓRNA GRANICA zobowiązania — każda faktura zakupowa z VAT '
                .'naliczonym ją obniży. To nie jest jeszcze kwota do zapłaty.';
            $breakdown->addText('VAT naliczony (zakupy)', 'nie podano — brak rejestru zakupów');
        } else {
            $breakdown->add(
                'VAT naliczony (zakupy)',
                $inputVat,
                $purchases->documentCount > 0 ? sprintf('z %d dokumentów', $purchases->documentCount) : null,
                'Ustawa o VAT, art. 86 ust. 1',
            );
        }

        if ($carryForwardIn->isPositive()) {
            $breakdown->add('Nadwyżka z poprzedniego okresu', $carryForwardIn, null, 'Ustawa o VAT, art. 87 ust. 1');
        }

        $balance = $outputVat->minus($inputVat)->minus($carryForwardIn);

        // Ordynacja podatkowa art. 63 § 1 — tax amounts are rounded to whole złoty.
        $rounded = $balance->roundedToZloty();

        if ($rounded->isPositive()) {
            $toPay = $rounded;
            $carryForwardOut = Money::zero();
            $breakdown->addTotal(
                'VAT do zapłaty',
                $toPay,
                sprintf(
                    '%s − %s%s, zaokrąglone do pełnych złotych',
                    $outputVat->format(''),
                    $inputVat->format(''),
                    $carryForwardIn->isPositive() ? ' − '.$carryForwardIn->format('') : '',
                ),
                'Ordynacja podatkowa, art. 63 § 1',
            );
        } else {
            $toPay = Money::zero();
            $carryForwardOut = Money::zero()->minus($rounded);
            $breakdown->addTotal('VAT do zapłaty', $toPay);
            $breakdown->add(
                'Nadwyżka podatku naliczonego (do przeniesienia lub zwrotu)',
                $carryForwardOut,
                null,
                'Ustawa o VAT, art. 87',
            );
        }

        $notes = array_merge(
            $notes,
            $this->exemptionLimitNotes($profile, $period, $version, $turnoverYearToDate),
        );

        $breakdown->addText('Deklaracja', $profile->vatSettlement->jpkStructure()
            .' — termin do '.$this->rates->vat()->constant('jpk_due_day_of_following_month')
            .' dnia miesiąca następującego po okresie');

        return new VatSettlement(
            $period,
            true,
            $outputVat,
            $inputVat,
            $toPay,
            $carryForwardOut,
            $breakdown,
            $notes,
            $profile->vatSettlement->jpkStructure(),
        );
    }

    private function exemptSettlement(
        TaxProfile $profile,
        Period $period,
        FiscalSalesReport $sales,
        \Poland\Rates\RateVersion $version,
        Breakdown $breakdown,
        ?Money $turnoverYearToDate,
    ): VatSettlement {
        $breakdown->addText('Status', $profile->vatStatus->label());
        $breakdown->add('Sprzedaż w okresie', $sales->grossTotal());
        $breakdown->addTotal(
            'VAT do zapłaty',
            Money::zero(),
            'podatnik zwolniony — nie nalicza i nie odlicza VAT',
            $profile->vatStatus->isLimitDependent()
                ? 'Ustawa o VAT, art. 113 ust. 1'
                : 'Ustawa o VAT, art. 43',
        );

        return new VatSettlement(
            $period,
            false,
            Money::zero(),
            Money::zero(),
            Money::zero(),
            Money::zero(),
            $breakdown,
            $this->exemptionLimitNotes($profile, $period, $version, $turnoverYearToDate),
            null,
        );
    }

    /**
     * Warn while the taxpayer can still act.
     *
     * Losing the art. 113 exemption is not a gradual event: the sale that
     * crosses the limit is itself taxable, and registration must happen before
     * it. A warning delivered after the fact is worthless, so the threshold is
     * checked against turnover accumulated so far, not against a closed year.
     *
     * @return list<string>
     */
    private function exemptionLimitNotes(
        TaxProfile $profile,
        Period $period,
        \Poland\Rates\RateVersion $version,
        ?Money $turnoverYearToDate,
    ): array {
        if (! $profile->vatStatus->isLimitDependent()) {
            return [];
        }

        $limit = $version->money('exemption_limit');

        if ($turnoverYearToDate === null) {
            return [sprintf(
                'Zwolnienie podmiotowe zależy od obrotu narastająco (limit %s w %d r.). '
                .'Obrót od początku roku nie został przekazany, więc bliskość limitu NIE została sprawdzona.',
                $limit->format(),
                $period->year,
            )];
        }

        if ($turnoverYearToDate->greaterThan($limit)) {
            return [sprintf(
                'LIMIT PRZEKROCZONY: obrót od początku roku %s przekracza limit %s. '
                .'Zwolnienie podmiotowe traci moc od czynności, którą przekroczono limit '
                .'(art. 113 ust. 5) — rejestracja VAT-R jest wymagana przed tą sprzedażą, '
                .'a ten raport nie odzwierciedla już statusu podatnika.',
                $turnoverYearToDate->format(),
                $limit->format(),
            )];
        }

        $warnAt = $limit->times((float) $this->rates->vat()->constant('exemption_limit_warning_at'));
        if (! $turnoverYearToDate->lessThan($warnAt)) {
            return [sprintf(
                'UWAGA: obrót od początku roku %s to %.1f%% limitu zwolnienia (%s). '
                .'Pozostało %s do przekroczenia.',
                $turnoverYearToDate->format(),
                $limit->grosze > 0 ? ($turnoverYearToDate->grosze / $limit->grosze) * 100 : 0.0,
                $limit->format(),
                $limit->minus($turnoverYearToDate)->format(),
            )];
        }

        return [];
    }
}
