<?php

declare(strict_types=1);

namespace Poland\Purchases;

/**
 * Where a cost goes in the revenue-and-expense ledger (KPiR).
 *
 * Irrelevant to ryczałt, which taxes revenue; decisive for the scale and the
 * flat tax, where the column decides how the cost is read. Kept to the four
 * columns a food shop actually uses.
 */
enum CostCategory: string
{
    /** KPiR column 10 — goods for resale and materials: drinks, meat, bread, vegetables, packaging. */
    case GoodsForResaleAndMaterials = 'goods_for_resale_and_materials';

    /** KPiR column 11 — side costs of purchase: transport, insurance of a delivery. */
    case PurchaseSideCosts = 'purchase_side_costs';

    /** KPiR column 12 — wages. Present for completeness; not expected in a one-person shop. */
    case Wages = 'wages';

    /** KPiR column 13 — other expenses: rent, utilities, platform commission, repairs, services. */
    case OtherExpenses = 'other_expenses';

    public function kpirColumn(): int
    {
        return match ($this) {
            self::GoodsForResaleAndMaterials => 10,
            self::PurchaseSideCosts => 11,
            self::Wages => 12,
            self::OtherExpenses => 13,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GoodsForResaleAndMaterials => 'Towary handlowe i materiały (kol. 10)',
            self::PurchaseSideCosts => 'Koszty uboczne zakupu (kol. 11)',
            self::Wages => 'Wynagrodzenia (kol. 12)',
            self::OtherExpenses => 'Pozostałe wydatki (kol. 13)',
        };
    }
}
