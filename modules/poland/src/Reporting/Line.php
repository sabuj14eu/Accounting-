<?php

declare(strict_types=1);

namespace Poland\Reporting;

use Poland\Domain\Money;

/**
 * One line of a calculation, carrying not just the number but how it was
 * reached and under what authority.
 *
 * A figure the taxpayer cannot re-derive is a figure they have to take on
 * faith, and tax liabilities are not a good place for faith.
 */
final class Line implements \JsonSerializable
{
    public function __construct(
        public readonly string $label,
        public readonly ?Money $amount = null,
        public readonly ?string $formula = null,
        public readonly ?string $legalBasis = null,
        public readonly ?string $rateSource = null,
        public readonly bool $emphasis = false,
        public readonly ?string $textValue = null,
    ) {
    }

    public function value(): string
    {
        return $this->textValue ?? ($this->amount?->format() ?? '—');
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'label' => $this->label,
            'amount' => $this->amount,
            'value' => $this->value(),
            'formula' => $this->formula,
            'legal_basis' => $this->legalBasis,
            'rate_source' => $this->rateSource,
            'emphasis' => $this->emphasis ?: null,
        ], static fn ($v): bool => $v !== null);
    }
}
