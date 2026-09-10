<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/** A physical count of one product on one day, by a named person. */
class InventoryCountModel extends Model
{
    protected $table = 'pl_inventory_counts';

    protected $guarded = [];

    protected $casts = [
        'quantity_thousandths' => 'int',
        'book_quantity_thousandths' => 'int',
        'is_opening' => 'bool',
        'counted_on' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }
}
