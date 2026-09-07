<?php

declare(strict_types=1);

namespace Shop\Money;

enum AllocationState: string
{
    case UNALLOCATED = 'UNALLOCATED';
    case PARTIALLY_ALLOCATED = 'PARTIALLY_ALLOCATED';
    case FULLY_ALLOCATED = 'FULLY_ALLOCATED';
    case OVER_ALLOCATED = 'OVER_ALLOCATED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function needsAttention(): bool
    {
        return $this !== self::FULLY_ALLOCATED;
    }
}
