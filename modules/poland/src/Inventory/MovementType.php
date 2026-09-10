<?php

declare(strict_types=1);

namespace Poland\Inventory;

/** Why stock moved. Stock moves when goods move or when somebody counted; never because a model concluded. */
enum MovementType: string
{
    /** An approved purchase invoice line for a tracked product. */
    case Purchase = 'purchase';

    /** The difference between the book quantity and a physical count. */
    case CountAdjustment = 'count_adjustment';

    /** An owner's explicit correction with a reason (breakage, gift, error). */
    case ManualAdjustment = 'manual_adjustment';

    /** Undoes an earlier movement when its invoice is corrected. Points at what it reverses. */
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'zakup (faktura)',
            self::CountAdjustment => 'korekta po inwentaryzacji',
            self::ManualAdjustment => 'korekta ręczna',
            self::Reversal => 'odwrócenie',
        };
    }
}
