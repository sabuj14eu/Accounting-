<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Poland\Inventory\StockUnit;
use Poland\Purchases\CostCategory;
use Poland\Purchases\ProductMapping;

/** A product this shop buys. Untracked by default. */
class ProductModel extends Model
{
    protected $table = 'pl_products';

    protected $guarded = [];

    protected $casts = [
        'inventory_tracked' => 'bool',
        'active' => 'bool',
        'tracking_changed_at' => 'datetime',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAliasModel::class, 'product_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovementModel::class, 'product_id');
    }

    public function counts(): HasMany
    {
        return $this->hasMany(InventoryCountModel::class, 'product_id');
    }

    public function stockUnit(): StockUnit
    {
        return StockUnit::from($this->stock_unit);
    }

    public function costCategory(): CostCategory
    {
        return CostCategory::from($this->default_cost_category);
    }

    public function toMapping(?ProductAliasModel $alias = null): ProductMapping
    {
        return new ProductMapping(
            (int) $this->getKey(),
            (string) $this->code,
            (string) $this->name,
            $this->stockUnit(),
            (bool) $this->inventory_tracked,
            $this->costCategory(),
            $alias?->pack_size !== null ? (float) $alias->pack_size : null,
            $alias?->pack_unit,
        );
    }
}
