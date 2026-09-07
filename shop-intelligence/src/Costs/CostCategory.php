<?php

declare(strict_types=1);

namespace Shop\Costs;

/**
 * The three cost groups from §12-§14.
 *
 * A shop owner does not think in a chart of accounts; they think "food",
 * "the building", "everything else". Keeping exactly those three, with free
 * subcategories underneath, is the difference between a page they read and a
 * page they close.
 */
enum CostCategory: string
{
    /** §12 — meat, bread, vegetables, drinks, packaging, napkins. */
    case FOOD_AND_MATERIALS = 'FOOD_AND_MATERIALS';

    /** §13 — rent, electricity, gas, water, internet, waste collection. */
    case PREMISES_AND_UTILITIES = 'PREMISES_AND_UTILITIES';

    /** §14 — wages, cleaning, repairs, equipment, insurance, POS and bank fees,
     *  platform fees, advertising, licences, software. */
    case OTHER_OPERATING = 'OTHER_OPERATING';

    public function label(): string
    {
        return match ($this) {
            self::FOOD_AND_MATERIALS => 'Food and materials',
            self::PREMISES_AND_UTILITIES => 'Premises and utilities',
            self::OTHER_OPERATING => 'Other operating costs',
        };
    }

    public function labelPl(): string
    {
        return match ($this) {
            self::FOOD_AND_MATERIALS => 'Towar i materiały',
            self::PREMISES_AND_UTILITIES => 'Lokal i media',
            self::OTHER_OPERATING => 'Pozostałe koszty operacyjne',
        };
    }

    /** Does this cost move with how much is sold? */
    public function isVariable(): bool
    {
        return $this === self::FOOD_AND_MATERIALS;
    }
}
