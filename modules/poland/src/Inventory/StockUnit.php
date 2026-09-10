<?php

declare(strict_types=1);

namespace Poland\Inventory;

/**
 * The unit a tracked product is counted in.
 *
 * A closed list: stock arithmetic across units is refused, so the list has to
 * be one the code knows. Invoice units are free text written by the supplier
 * ("op.", "karton", "szt", "kg") and are MAPPED here, never trusted as-is.
 */
enum StockUnit: string
{
    case Piece = 'szt';
    case Kilogram = 'kg';
    case Litre = 'l';
    case Pack = 'op';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'szt',
            self::Kilogram => 'kg',
            self::Litre => 'l',
            self::Pack => 'op.',
        };
    }

    /**
     * Best-effort reading of a supplier's unit text. Null when it is not one
     * this system knows — which is a question for the owner, not a default.
     */
    public static function fromInvoiceUnit(?string $unit): ?self
    {
        if ($unit === null) {
            return null;
        }

        $normalised = mb_strtolower(trim(rtrim(trim($unit), '.')));

        return match ($normalised) {
            'szt', 'sztuka', 'sztuk', 'pcs', 'pc', 'ea' => self::Piece,
            'kg', 'kilogram' => self::Kilogram,
            'l', 'ltr', 'litr' => self::Litre,
            'op', 'opak', 'opakowanie', 'karton', 'krt', 'zgrz', 'zgrzewka', 'paleta', 'skrz' => self::Pack,
            default => null,
        };
    }
}
