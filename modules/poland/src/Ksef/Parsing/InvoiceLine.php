<?php

declare(strict_types=1);

namespace Poland\Ksef\Parsing;

use Poland\Domain\Money;

/**
 * One `FaWiersz` row of a structured invoice, as the issuer wrote it.
 *
 * Every field the document did not supply stays null. A line without a
 * quantity is not a line of one; a line without a VAT amount has its VAT
 * DERIVED from net × rate and says so in {@see self::$vatIsDerived}, because a
 * derived figure and a stated figure are different kinds of evidence even when
 * they agree.
 */
final class InvoiceLine implements \JsonSerializable
{
    /** P_12 values that are rates, mapped to the designation the engine uses. */
    private const RATE_DESIGNATIONS = [
        '23' => '0.23',
        '8' => '0.08',
        '5' => '0.05',
        '0' => '0',
        '4' => '0.04',
        '7' => '0.07',
        'zw' => 'zw',
        'np' => 'np',
    ];

    /**
     * @param array<string,mixed> $raw every child element of the row, verbatim
     */
    public function __construct(
        public readonly int $lineNo,
        public readonly ?string $description,
        public readonly ?string $quantity,
        public readonly ?string $unit,
        public readonly ?Money $unitNetPrice,
        public readonly ?Money $net,
        /** P_12 as written: "23", "8", "5", "0", "zw", "np", "oo", … or null. */
        public readonly ?string $vatRate,
        public readonly ?Money $vat,
        public readonly bool $vatIsDerived,
        public readonly ?Money $gross,
        public readonly bool $grossIsDerived,
        public readonly ?string $supplierIndex = null,
        public readonly ?string $gtin = null,
        public readonly ?string $pkwiu = null,
        public readonly ?string $gtu = null,
        public readonly ?string $procedure = null,
        public readonly array $raw = [],
    ) {
    }

    /** The engine's designation for this line's rate, or null when it is not one the engine knows. */
    public function designation(): ?string
    {
        if ($this->vatRate === null) {
            return null;
        }

        return self::RATE_DESIGNATIONS[strtolower(trim($this->vatRate))] ?? null;
    }

    /** Whether the rate is one the engine can book without a person. */
    public function vatRateIsKnown(): bool
    {
        return $this->designation() !== null;
    }

    /** Fields an accounting line cannot be made without. */
    public function missingFields(): array
    {
        $missing = [];
        foreach (['description' => $this->description, 'net' => $this->net, 'vat_rate' => $this->vatRate] as $name => $value) {
            if ($value === null) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    public function quantityAsFloat(): ?float
    {
        if ($this->quantity === null) {
            return null;
        }

        $normalised = str_replace([',', ' '], ['.', ''], trim($this->quantity));

        return is_numeric($normalised) ? (float) $normalised : null;
    }

    /**
     * Derive VAT from a stated net and a numeric rate, rounded half-up to grosze.
     * Not a guess: arithmetic on two values the issuer stated.
     */
    public static function deriveVat(Money $net, string $rate): ?Money
    {
        $designation = self::RATE_DESIGNATIONS[strtolower(trim($rate))] ?? null;
        if ($designation === null) {
            return null;
        }
        if (in_array($designation, ['zw', 'np'], true)) {
            return Money::zero();
        }

        return $net->times((float) $designation);
    }

    public function jsonSerialize(): array
    {
        return [
            'line_no' => $this->lineNo,
            'description' => $this->description ?? ParsedInvoice::MISSING,
            'supplier_index' => $this->supplierIndex,
            'gtin' => $this->gtin,
            'pkwiu' => $this->pkwiu,
            'quantity' => $this->quantity ?? ParsedInvoice::MISSING,
            'unit' => $this->unit ?? ParsedInvoice::MISSING,
            'unit_net_price' => $this->unitNetPrice,
            'net' => $this->net ?? ParsedInvoice::MISSING,
            'vat_rate' => $this->vatRate ?? ParsedInvoice::MISSING,
            'designation' => $this->designation(),
            'vat' => $this->vat ?? ParsedInvoice::MISSING,
            'vat_is_derived' => $this->vatIsDerived,
            'gross' => $this->gross ?? ParsedInvoice::MISSING,
            'gross_is_derived' => $this->grossIsDerived,
            'gtu' => $this->gtu,
            'procedure' => $this->procedure,
            'missing' => $this->missingFields(),
        ];
    }
}
