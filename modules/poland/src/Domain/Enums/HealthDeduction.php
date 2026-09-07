<?php

declare(strict_types=1);

namespace Poland\Domain\Enums;

/** How paid health contributions interact with the PIT base under each regime. */
enum HealthDeduction: string
{
    case None = 'none';
    case HalfOfPaid = 'half_of_paid';
    case CappedAnnualLimit = 'capped_annual_limit';
}
