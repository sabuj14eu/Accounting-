<?php

declare(strict_types=1);

namespace Poland\Ksef\Fa3;

use Poland\Domain\Money;

/** The `P_13_*`/`P_14_*` sums and `P_15`, derived from the lines by addition only. */
final class Fa3Totals
{
    /**
     * @param array<string, array{net: Money, vat: Money}> $byGroup keyed by Fa3VatRate::totalsGroup()
     */
    public function __construct(
        public readonly array $byGroup,
        public readonly Money $net,
        public readonly Money $vat,
        public readonly Money $gross,
    ) {
    }

    /** @param list<Fa3Line> $lines */
    public static function fromLines(array $lines): self
    {
        $byGroup = [];
        $net = Money::zero();
        $vat = Money::zero();
        foreach ($lines as $line) {
            $group = $line->rate->totalsGroup();
            $byGroup[$group] ??= ['net' => Money::zero(), 'vat' => Money::zero()];
            $byGroup[$group]['net'] = $byGroup[$group]['net']->plus($line->netValue);
            $byGroup[$group]['vat'] = $byGroup[$group]['vat']->plus($line->vatAmount);
            $net = $net->plus($line->netValue);
            $vat = $vat->plus($line->vatAmount);
        }
        ksort($byGroup, SORT_NATURAL);

        return new self($byGroup, $net, $vat, $net->plus($vat));
    }
}
