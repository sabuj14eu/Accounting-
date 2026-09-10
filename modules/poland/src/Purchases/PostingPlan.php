<?php

declare(strict_types=1);

namespace Poland\Purchases;

use DateTimeImmutable;
use InvalidArgumentException;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Inventory\MovementType;
use Poland\Inventory\StockMovement;
use Poland\Inventory\StockQuantity;
use Poland\Inventory\StockUnit;
use Poland\Ksef\Parsing\ParsedInvoice;

/**
 * Exactly what approving an invoice will do, computed before anything is
 * written and shown to the owner in words.
 *
 * Amounts come from the HEADER totals (what the issuer declared and KSeF
 * accepted), split by rate. Lines decide stock movements and the cost
 * category; they never change the amounts posted.
 *
 * The VAT period is never earlier than the month the invoice was received
 * (for a KSeF invoice, the month its KSeF number was assigned) and never later
 * than the statutory number of following periods. Both limits are enforced
 * here, so a later screen cannot pick a month the law does not allow.
 */
final class PostingPlan implements \JsonSerializable
{
    /**
     * @param array<string,array{net: Money, vat: Money}> $byRate
     * @param list<StockMovement> $movements
     * @param list<string> $blockers
     * @param list<string> $notes
     */
    private function __construct(
        public readonly ?Period $bookingPeriod,
        public readonly Period $receiptPeriod,
        public readonly ?Period $vatPeriod,
        public readonly array $byRate,
        public readonly Money $deductibleNet,
        public readonly Money $deductibleInputVat,
        public readonly Money $nonDeductibleNet,
        public readonly Money $nonDeductibleVat,
        public readonly CostCategory $costCategory,
        public readonly float $deductibleShare,
        public readonly array $movements,
        public readonly int $allowedFollowingPeriods,
        public readonly array $blockers,
        public readonly array $notes,
    ) {
    }

    /**
     * @param list<LineResolution> $resolutions one per invoice line
     * @param int $allowedFollowingPeriods from the VAT rate table (art. 86 ust. 11)
     */
    public static function build(
        ParsedInvoice $invoice,
        array $resolutions,
        DateTimeImmutable $receivedAt,
        int $allowedFollowingPeriods,
        CostCategory $costCategory,
        float $deductibleShare = 1.0,
        ?Period $vatPeriod = null,
    ): self {
        if ($deductibleShare < 0.0 || $deductibleShare > 1.0) {
            throw new InvalidArgumentException('Deductible share must be between 0 and 1.');
        }
        if ($allowedFollowingPeriods < 0) {
            throw new InvalidArgumentException('Allowed following periods cannot be negative.');
        }

        $blockers = [];
        $notes = [];
        $meta = $invoice->metadata;

        $receiptPeriod = Period::of((int) $receivedAt->format('Y'), (int) $receivedAt->format('n'));

        $bookingPeriod = null;
        if ($meta->invoiceDate === null) {
            $blockers[] = 'Brak daty wystawienia — nie wiadomo, do którego miesiąca zaksięgować.';
        } else {
            $bookingPeriod = Period::of((int) $meta->invoiceDate->format('Y'), (int) $meta->invoiceDate->format('n'));
        }

        if ($meta->currency !== null && strtoupper($meta->currency) !== 'PLN') {
            $blockers[] = sprintf(
                'Faktura w walucie %s — bez kursu NBP z dnia poprzedzającego nie da się jej zaksięgować, '
                .'a adapter kursów nie jest skonfigurowany.',
                $meta->currency,
            );
        }

        if ($meta->net === null || $meta->vat === null) {
            $blockers[] = 'Nagłówek faktury nie podaje kwoty netto lub VAT (MISSING_FIELD) — nie ma czego zaksięgować.';
        }

        // Default VAT period: the later of invoice month and receipt month.
        // Deduction may not start before receipt (art. 86 ust. 10b).
        $defaultVat = $bookingPeriod !== null && $bookingPeriod->isAfter($receiptPeriod) ? $bookingPeriod : $receiptPeriod;
        $chosenVat = $vatPeriod ?? $defaultVat;
        $windowEnd = self::addMonths($receiptPeriod, $allowedFollowingPeriods);
        if ($chosenVat->isBefore($receiptPeriod)) {
            throw new InvalidArgumentException(sprintf(
                'Okres odliczenia VAT %s jest wcześniejszy niż miesiąc otrzymania faktury (%s). '
                .'Prawo do odliczenia nie powstaje przed otrzymaniem faktury.',
                $chosenVat->toString(),
                $receiptPeriod->toString(),
            ));
        }
        if ($chosenVat->isAfter($windowEnd)) {
            throw new InvalidArgumentException(sprintf(
                'Okres odliczenia VAT %s wykracza poza dozwolone okno (otrzymanie %s + %d okresów = do %s).',
                $chosenVat->toString(),
                $receiptPeriod->toString(),
                $allowedFollowingPeriods,
                $windowEnd->toString(),
            ));
        }

        $byRate = [];
        $totalNet = Money::zero();
        $totalVat = Money::zero();
        foreach ($invoice->netByVatRate as $label => $amounts) {
            $byRate[$label] = ['net' => $amounts['net'], 'vat' => $amounts['vat']];
            $totalNet = $totalNet->plus($amounts['net']);
            $totalVat = $totalVat->plus($amounts['vat']);
        }
        if ($byRate === [] && $meta->net !== null && $meta->vat !== null) {
            // A document with totals but no per-rate split (e.g. KSeF metadata only).
            $byRate['inna'] = ['net' => $meta->net, 'vat' => $meta->vat];
            $totalNet = $meta->net;
            $totalVat = $meta->vat;
            $notes[] = 'Faktura nie podaje podziału na stawki — zaksięgowano sumę jako "inna".';
        }

        $deductibleNet = $totalNet->times($deductibleShare);
        $deductibleVat = $totalVat->times($deductibleShare);
        $nonDeductibleNet = $totalNet->minus($deductibleNet);
        $nonDeductibleVat = $totalVat->minus($deductibleVat);
        if ($deductibleShare < 1.0) {
            $notes[] = sprintf('Odliczenie %d%% — pozostała część nie wchodzi do rejestrów.', (int) round($deductibleShare * 100));
        }

        $movements = [];
        $unmapped = 0;
        $derived = 0;
        foreach ($resolutions as $resolution) {
            $line = $resolution->line;
            if (! $line->vatRateIsKnown()) {
                $blockers[] = sprintf(
                    'Pozycja %d ("%s"): stawka VAT "%s" nie jest znana silnikowi — wymaga decyzji.',
                    $line->lineNo,
                    $line->description ?? ParsedInvoice::MISSING,
                    $line->vatRate ?? ParsedInvoice::MISSING,
                );
            }
            if ($line->vatIsDerived) {
                $derived++;
            }
            if (! $resolution->isMapped()) {
                $unmapped++;

                continue;
            }
            if (! $resolution->isTracked()) {
                continue;
            }

            $movement = self::movementFor($resolution, $blockers);
            if ($movement !== null) {
                $movements[] = $movement;
            }
        }

        if ($unmapped > 0) {
            $notes[] = sprintf('%d pozycji bez przypisanego produktu — tylko księgowanie, bez ruchu magazynowego.', $unmapped);
        }
        if ($derived > 0) {
            $notes[] = sprintf('%d pozycji ma VAT wyliczony z netto × stawka (dokument nie podał kwoty VAT w pozycji).', $derived);
        }
        if ($invoice->paymentTerms()->settledAtIssue()) {
            $notes[] = 'Zapłacono przy wystawieniu (gotówka/karta) — przelew nie jest oczekiwany na wyciągu.';
        }

        return new self(
            bookingPeriod: $bookingPeriod,
            receiptPeriod: $receiptPeriod,
            vatPeriod: $chosenVat,
            byRate: $byRate,
            deductibleNet: $deductibleNet,
            deductibleInputVat: $deductibleVat,
            nonDeductibleNet: $nonDeductibleNet,
            nonDeductibleVat: $nonDeductibleVat,
            costCategory: $costCategory,
            deductibleShare: $deductibleShare,
            movements: $movements,
            allowedFollowingPeriods: $allowedFollowingPeriods,
            blockers: array_values(array_unique($blockers)),
            notes: $notes,
        );
    }

    /** @param list<string> $blockers */
    private static function movementFor(LineResolution $resolution, array &$blockers): ?StockMovement
    {
        $line = $resolution->line;
        $mapping = $resolution->mapping;
        if ($mapping === null) {
            return null;
        }

        $quantity = $line->quantityAsFloat();
        if ($quantity === null) {
            $blockers[] = sprintf(
                'Pozycja %d ("%s") dotyczy śledzonego produktu %s, ale nie podaje ilości — ruch magazynowy niemożliwy.',
                $line->lineNo,
                $line->description ?? ParsedInvoice::MISSING,
                $mapping->name,
            );

            return null;
        }

        $invoiceUnit = StockUnit::fromInvoiceUnit($line->unit);
        $note = null;

        if ($mapping->packSize !== null) {
            $stockQuantity = StockQuantity::of($quantity, $mapping->stockUnit)->times($mapping->packSize);
            $note = sprintf('%s %s × %s', self::trimNumber($quantity), $line->unit ?? $mapping->packUnit ?? 'op.', self::trimNumber($mapping->packSize));
        } elseif ($invoiceUnit === $mapping->stockUnit) {
            $stockQuantity = StockQuantity::of($quantity, $mapping->stockUnit);
        } else {
            $blockers[] = sprintf(
                'Pozycja %d: %s %s produktu %s liczonego w %s — ile %s mieści jedno "%s"? Podaj wielkość opakowania (zapamiętamy).',
                $line->lineNo,
                self::trimNumber($quantity),
                $line->unit ?? '(brak jednostki)',
                $mapping->name,
                $mapping->stockUnit->label(),
                $mapping->stockUnit->label(),
                $line->unit ?? '?',
            );

            return null;
        }

        return new StockMovement(
            $mapping->code,
            $mapping->name,
            $stockQuantity,
            MovementType::Purchase,
            $line->lineNo,
            $note,
        );
    }

    public function isPostable(): bool
    {
        return $this->blockers === [];
    }

    /** The last period in which this invoice's input VAT may still be deducted. */
    public function deductionWindowEnd(): Period
    {
        return self::addMonths($this->receiptPeriod, $this->allowedFollowingPeriods);
    }

    public function deductionWindowClosed(Period $asOf): bool
    {
        return $asOf->isAfter($this->deductionWindowEnd());
    }

    /** The same plan with a different (allowed) VAT period. Throws outside the window. */
    public function withVatPeriod(Period $vatPeriod): self
    {
        if ($vatPeriod->isBefore($this->receiptPeriod)) {
            throw new InvalidArgumentException(sprintf(
                'Okres odliczenia VAT %s jest wcześniejszy niż miesiąc otrzymania faktury (%s).',
                $vatPeriod->toString(),
                $this->receiptPeriod->toString(),
            ));
        }
        if ($vatPeriod->isAfter($this->deductionWindowEnd())) {
            throw new InvalidArgumentException(sprintf(
                'Okres odliczenia VAT %s wykracza poza dozwolone okno (do %s).',
                $vatPeriod->toString(),
                $this->deductionWindowEnd()->toString(),
            ));
        }

        return new self(
            $this->bookingPeriod, $this->receiptPeriod, $vatPeriod, $this->byRate,
            $this->deductibleNet, $this->deductibleInputVat, $this->nonDeductibleNet, $this->nonDeductibleVat,
            $this->costCategory, $this->deductibleShare, $this->movements, $this->allowedFollowingPeriods,
            $this->blockers, $this->notes,
        );
    }

    /**
     * The plan that undoes this one — for a correction invoice. Every amount
     * and every movement is negated; nothing is deleted.
     */
    public function negated(): self
    {
        $byRate = [];
        foreach ($this->byRate as $label => $amounts) {
            $byRate[$label] = [
                'net' => Money::zero()->minus($amounts['net']),
                'vat' => Money::zero()->minus($amounts['vat']),
            ];
        }

        return new self(
            $this->bookingPeriod,
            $this->receiptPeriod,
            $this->vatPeriod,
            $byRate,
            Money::zero()->minus($this->deductibleNet),
            Money::zero()->minus($this->deductibleInputVat),
            Money::zero()->minus($this->nonDeductibleNet),
            Money::zero()->minus($this->nonDeductibleVat),
            $this->costCategory,
            $this->deductibleShare,
            array_map(static fn (StockMovement $m): StockMovement => $m->negated(), $this->movements),
            $this->allowedFollowingPeriods,
            $this->blockers,
            array_merge(['ODWRÓCENIE wcześniejszego księgowania.'], $this->notes),
        );
    }

    /** What approving will do, in one sentence the owner reads before tapping. */
    public function describe(): string
    {
        if (! $this->isPostable()) {
            return 'Nie można zatwierdzić: '.implode(' ', $this->blockers);
        }

        $parts = [];
        foreach ($this->byRate as $label => $amounts) {
            $parts[] = sprintf('%s netto + %s VAT (%s)', $amounts['net']->format(), $amounts['vat']->format(), $label);
        }

        $sentence = sprintf(
            'Księguje %s w miesiącu %s (VAT w okresie %s, %s).',
            implode(' oraz ', $parts),
            $this->bookingPeriod?->label() ?? '?',
            $this->vatPeriod?->toString() ?? '?',
            $this->costCategory->label(),
        );

        if ($this->movements !== []) {
            $sentence .= ' Zwiększa stan: '.implode('; ', array_map(
                static fn (StockMovement $m): string => $m->describe().($m->note !== null ? ' ('.$m->note.')' : ''),
                $this->movements,
            )).'.';
        } else {
            $sentence .= ' Bez ruchu magazynowego.';
        }

        foreach ($this->notes as $note) {
            $sentence .= ' '.$note;
        }

        return $sentence;
    }

    private static function addMonths(Period $period, int $months): Period
    {
        for ($i = 0; $i < $months; $i++) {
            $period = $period->next();
        }

        return $period;
    }

    private static function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }

    public function jsonSerialize(): array
    {
        return [
            'booking_period' => $this->bookingPeriod?->toString(),
            'receipt_period' => $this->receiptPeriod->toString(),
            'vat_period' => $this->vatPeriod?->toString(),
            'deduction_window_end' => $this->deductionWindowEnd()->toString(),
            'by_rate' => $this->byRate,
            'deductible_net' => $this->deductibleNet,
            'deductible_input_vat' => $this->deductibleInputVat,
            'non_deductible_net' => $this->nonDeductibleNet,
            'non_deductible_vat' => $this->nonDeductibleVat,
            'cost_category' => $this->costCategory->value,
            'kpir_column' => $this->costCategory->kpirColumn(),
            'deductible_share' => $this->deductibleShare,
            'movements' => $this->movements,
            'blockers' => $this->blockers,
            'notes' => $this->notes,
            'postable' => $this->isPostable(),
            'description' => $this->describe(),
        ];
    }
}
