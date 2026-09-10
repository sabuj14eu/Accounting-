<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per seller NIP ever seen on an incoming invoice. Derived from documents, never typed. */
class SupplierModel extends Model
{
    protected $table = 'pl_suppliers';

    protected $guarded = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'invoice_count' => 'int',
    ];

    public function isFirstTime(): bool
    {
        return (int) $this->invoice_count <= 1;
    }
}
