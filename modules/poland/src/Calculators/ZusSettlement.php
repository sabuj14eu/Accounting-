<?php

declare(strict_types=1);

namespace Poland\Calculators;

use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Reporting\Breakdown;

/** ZUS due for one month: social contributions plus the health contribution. */
final class ZusSettlement implements \JsonSerializable
{
    /**
     * @param array<string,Money> $socialComponents
     * @param list<string> $notes
     */
    public function __construct(
        public readonly Period $period,
        public readonly Money $contributionBase,
        public readonly array $socialComponents,
        public readonly Money $socialTotal,
        public readonly Money $health,
        public readonly Money $total,
        public readonly Breakdown $breakdown,
        public readonly array $notes = [],
        public readonly ?string $healthBasis = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'period' => $this->period->toString(),
            'contribution_base' => $this->contributionBase,
            'social_components' => $this->socialComponents,
            'social_total' => $this->socialTotal,
            'health' => $this->health,
            'health_basis' => $this->healthBasis,
            'total' => $this->total,
            'notes' => $this->notes,
            'breakdown' => $this->breakdown,
        ];
    }
}
