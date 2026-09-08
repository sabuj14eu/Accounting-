<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use DateTimeImmutable;
use Poland\Domain\Money;

/**
 * Deterministic: the same snapshot always yields the same Fa3Invoice.
 *
 * It refuses rather than fills in. A buyer without a confirmed identifier, a
 * line without a VAT rate for a VAT payer, an exempt sale without its legal
 * basis — each is a reason in {@see MappingRefused}, and the submission is
 * BLOCKED with the list, so the person fixes the record instead of KSeF
 * rejecting a guess.
 */
final class ErpInvoiceMapper
{
    public function map(ErpInvoiceSnapshot $s): Fa3Invoice
    {
        $reasons = [];

        $seller = null;
        try {
            $seller = new Fa3Seller(
                preg_replace('/\D/', '', $s->seller['nip']) ?? '',
                $s->seller['name'],
                new Fa3Address($s->seller['country'], $s->seller['line1'], $this->blankToNull($s->seller['line2'] ?? null)),
                $this->blankToNull($s->seller['email'] ?? null),
                $this->blankToNull($s->seller['phone'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            $reasons[] = 'Sprzedawca: '.$e->getMessage();
        }

        $buyer = null;
        try {
            $buyer = $this->buyer($s->buyer);
        } catch (\InvalidArgumentException $e) {
            $reasons[] = 'Nabywca: '.$e->getMessage();
        }

        $lines = [];
        foreach ($s->lines as $index => $line) {
            try {
                $lines[] = $this->line($index + 1, $line, $s->vatExemptTaxpayer);
            } catch (\InvalidArgumentException $e) {
                $reasons[] = sprintf('Pozycja %d: %s', $index + 1, $e->getMessage());
            }
        }
        if ($s->lines === []) {
            $reasons[] = 'Faktura nie ma pozycji.';
        }

        $payment = null;
        try {
            $payment = new Fa3Payment(
                $s->paid,
                $this->date($s->paidOn),
                $this->date($s->dueDate),
                $this->blankToNull($s->paymentForm),
                $this->blankToNull($s->bankAccount === null ? null : preg_replace('/\s+/', '', $s->bankAccount)),
                $this->blankToNull($s->bankName),
            );
        } catch (\InvalidArgumentException $e) {
            $reasons[] = 'Płatność: '.$e->getMessage();
        }

        $correction = null;
        if ($s->correction !== null) {
            try {
                $correction = new Fa3Correction(
                    $s->correction['reason'],
                    new DateTimeImmutable($s->correction['corrected_issue_date']),
                    $s->correction['corrected_number'],
                    $this->blankToNull($s->correction['corrected_ksef_number'] ?? null),
                    $this->blankToNull($s->correction['effect_type'] ?? null),
                );
            } catch (\Exception $e) {
                $reasons[] = 'Korekta: '.$e->getMessage();
            }
        }

        if ($reasons !== [] || $seller === null || $buyer === null) {
            throw new MappingRefused($reasons);
        }

        try {
            return new Fa3Invoice(
                $seller,
                $buyer,
                strtoupper($s->currency),
                new DateTimeImmutable($s->issueDate),
                $s->number,
                $lines,
                $this->date($s->saleDate),
                $this->blankToNull($s->issuePlace),
                $payment,
                $s->type,
                $correction,
                $this->blankToNull($s->exemptionLegalBasis),
                $s->splitPayment,
            );
        } catch (\Exception $e) {
            throw new MappingRefused([$e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $b */
    private function buyer(array $b): Fa3Buyer
    {
        $type = $b['identifier_type'] ?? null;
        $identifier = match ($type) {
            Fa3BuyerIdentifier::NIP => Fa3BuyerIdentifier::nip((string) ($b['identifier_value'] ?? '')),
            Fa3BuyerIdentifier::VAT_UE => Fa3BuyerIdentifier::vatUe((string) ($b['identifier_country'] ?? ''), (string) ($b['identifier_value'] ?? '')),
            Fa3BuyerIdentifier::OTHER => Fa3BuyerIdentifier::other((string) ($b['identifier_value'] ?? ''), $this->blankToNull($b['identifier_country'] ?? null)),
            Fa3BuyerIdentifier::NONE => Fa3BuyerIdentifier::none(),
            default => throw new \InvalidArgumentException(
                'nie ustalono identyfikatora podatkowego nabywcy. Wskaż NIP, numer VAT UE, inny identyfikator albo potwierdź, że nabywca go nie posiada (konsument). System nie zgaduje.',
            ),
        };
        $address = null;
        if ($this->blankToNull($b['line1'] ?? null) !== null) {
            $address = new Fa3Address((string) ($b['country'] ?? 'PL'), (string) $b['line1'], $this->blankToNull($b['line2'] ?? null));
        }

        return new Fa3Buyer(
            $identifier,
            $this->blankToNull($b['name'] ?? null),
            $address,
            $this->blankToNull($b['email'] ?? null),
            (bool) ($b['jst'] ?? false),
            (bool) ($b['gv'] ?? false),
        );
    }

    /** @param array<string,mixed> $l */
    private function line(int $number, array $l, bool $vatExemptTaxpayer): Fa3Line
    {
        $rateValue = $l['tax_rate'] ?? null;
        if ($rateValue === null || $rateValue === '') {
            if (! $vatExemptTaxpayer) {
                throw new \InvalidArgumentException('brak stawki VAT na pozycji, a podatnik rozlicza VAT. Przypisz stawkę w księgowości.');
            }
            $rate = Fa3VatRate::Exempt;
        } else {
            $rate = Fa3VatRate::fromErp($rateValue, $l['zero_kind'] ?? null);
        }

        $quantity = $l['quantity'] ?? null;
        $quantityString = $quantity === null ? null : $this->decimal((string) $quantity, 6);
        $unitPrice = $l['unit_price'] ?? null;
        $unitPriceString = $unitPrice === null ? null : $this->decimal((string) $unitPrice, 8);

        return new Fa3Line(
            $number,
            (string) $l['description'],
            $rate,
            Money::parse((string) $l['amount']),
            Money::parse((string) ($l['tax_amount'] ?? '0')),
            $quantityString,
            $this->blankToNull($l['unit'] ?? null),
            $unitPriceString,
            null,
            $this->blankToNull($l['code'] ?? null),
            $this->blankToNull($l['gtu'] ?? null),
        );
    }

    private function decimal(string $value, int $maxFraction): string
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('wartość "%s" nie jest liczbą.', $value));
        }
        $formatted = rtrim(rtrim(number_format((float) $value, $maxFraction, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        $value = $this->blankToNull($value);

        return $value === null ? null : new DateTimeImmutable(substr($value, 0, 10));
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
