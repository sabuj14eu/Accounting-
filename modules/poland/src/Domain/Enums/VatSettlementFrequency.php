<?php

declare(strict_types=1);

namespace Poland\Domain\Enums;

/** Monthly (JPK_V7M) or quarterly (JPK_V7K) VAT settlement. */
enum VatSettlementFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';

    public function jpkStructure(): string
    {
        return $this === self::Monthly ? 'JPK_V7M' : 'JPK_V7K';
    }

    public function label(): string
    {
        return $this === self::Monthly ? 'Miesięcznie (JPK_V7M)' : 'Kwartalnie (JPK_V7K)';
    }
}
