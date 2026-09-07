<?php

declare(strict_types=1);

namespace Poland\Reporting;

use Poland\Domain\Money;

/** An ordered, printable derivation of one settlement figure. */
final class Breakdown implements \JsonSerializable
{
    /** @var list<Line> */
    private array $lines = [];

    public function __construct(public readonly string $title)
    {
    }

    public function add(
        string $label,
        ?Money $amount = null,
        ?string $formula = null,
        ?string $legalBasis = null,
        ?string $rateSource = null,
        bool $emphasis = false,
    ): self {
        $this->lines[] = new Line($label, $amount, $formula, $legalBasis, $rateSource, $emphasis);

        return $this;
    }

    public function addText(string $label, string $value, ?string $legalBasis = null): self
    {
        $this->lines[] = new Line($label, null, null, $legalBasis, null, false, $value);

        return $this;
    }

    public function addTotal(string $label, Money $amount, ?string $formula = null, ?string $legalBasis = null): self
    {
        return $this->add($label, $amount, $formula, $legalBasis, null, true);
    }

    /** @return list<Line> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function jsonSerialize(): array
    {
        return ['title' => $this->title, 'lines' => $this->lines];
    }
}
