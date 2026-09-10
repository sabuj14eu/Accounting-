<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Append-only. Stock history that can be edited proves nothing. */
class InventoryMovementModel extends Model
{
    protected $table = 'pl_inventory_movements';

    protected $guarded = [];

    protected $casts = [
        'quantity_thousandths' => 'int',
        'source_id' => 'int',
        'source_line_no' => 'int',
        'occurred_on' => 'date',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Ruchy magazynowe są niezmienne. Pomyłkę koryguje nowy ruch z przyczyną.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Ruchów magazynowych nie usuwa się.');
        });
    }

    public function product()
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }
}
