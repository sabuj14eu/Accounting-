<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/** How one supplier names one of our products, plus the pack size for that spelling. */
class ProductAliasModel extends Model
{
    protected $table = 'pl_product_aliases';

    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }
}
