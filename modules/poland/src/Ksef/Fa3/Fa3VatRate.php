<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

/**
 * `TStawkaPodatku` of the pinned FA(3) XSD, verbatim, and the totals element
 * each rate feeds. The list is the schema's, not this application's: a rate
 * the schema does not know cannot be expressed and is refused at construction.
 */
enum Fa3VatRate: string
{
    case Rate23 = '23';
    case Rate22 = '22';
    case Rate8 = '8';
    case Rate7 = '7';
    case Rate5 = '5';
    case Rate4 = '4';
    case Rate3 = '3';
    case ZeroDomestic = '0 KR';
    case ZeroIntraCommunity = '0 WDT';
    case ZeroExport = '0 EX';
    case Exempt = 'zw';
    case ReverseCharge = 'oo';
    case NotTaxableI = 'np I';
    case NotTaxableII = 'np II';

    /** From a percentage the ERP stores (23, 8, 5, 0) or one of the schema labels. */
    public static function fromErp(string|int|float $value, ?string $zeroKind = null): self
    {
        if (is_string($value) && self::tryFrom($value) !== null) {
            return self::from($value);
        }
        $numeric = is_numeric($value) ? (float) $value : null;
        if ($numeric === null) {
            throw new \InvalidArgumentException(sprintf('Stawka VAT "%s" nie występuje w schemacie FA(3).', (string) $value));
        }

        return match (true) {
            abs($numeric - 23.0) < 0.001 => self::Rate23,
            abs($numeric - 22.0) < 0.001 => self::Rate22,
            abs($numeric - 8.0) < 0.001 => self::Rate8,
            abs($numeric - 7.0) < 0.001 => self::Rate7,
            abs($numeric - 5.0) < 0.001 => self::Rate5,
            abs($numeric - 4.0) < 0.001 => self::Rate4,
            abs($numeric - 3.0) < 0.001 => self::Rate3,
            abs($numeric) < 0.001 => match ($zeroKind) {
                'WDT' => self::ZeroIntraCommunity,
                'EX' => self::ZeroExport,
                'zw' => self::Exempt,
                default => self::ZeroDomestic,
            },
            default => throw new \InvalidArgumentException(sprintf('Stawka VAT %s%% nie występuje w schemacie FA(3) — faktura wymaga przeglądu.', rtrim(rtrim(number_format($numeric, 2, '.', ''), '0'), '.'))),
        };
    }

    /** Percentage for arithmetic checks, null for rates that carry no tax. */
    public function percent(): ?int
    {
        return match ($this) {
            self::Rate23 => 23, self::Rate22 => 22, self::Rate8 => 8, self::Rate7 => 7,
            self::Rate5 => 5, self::Rate4 => 4, self::Rate3 => 3,
            self::ZeroDomestic, self::ZeroIntraCommunity, self::ZeroExport => 0,
            default => null,
        };
    }

    public function taxesVat(): bool
    {
        return in_array($this, [self::Rate23, self::Rate22, self::Rate8, self::Rate7, self::Rate5, self::Rate4, self::Rate3], true);
    }

    /** The `P_13_*` element this rate's net is summed into, and its `P_14_*` partner. */
    public function totalsGroup(): string
    {
        return match ($this) {
            self::Rate23, self::Rate22 => '1',
            self::Rate8, self::Rate7 => '2',
            self::Rate5, self::Rate4 => '3',
            self::Rate3 => '4',
            self::ZeroDomestic => '6_1',
            self::ZeroIntraCommunity => '6_2',
            self::ZeroExport => '6_3',
            self::Exempt => '7',
            self::NotTaxableI => '8',
            self::NotTaxableII => '9',
            self::ReverseCharge => '10',
        };
    }

    public function isExempt(): bool
    {
        return $this === self::Exempt;
    }
}
