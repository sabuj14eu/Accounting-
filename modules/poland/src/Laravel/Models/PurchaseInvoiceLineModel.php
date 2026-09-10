<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/** One `FaWiersz` row as stored; the parsed value object is re-derived from the immutable XML. */
class PurchaseInvoiceLineModel extends Model
{
    protected $table = 'pl_purchase_invoice_lines';

    protected $guarded = [];

    protected $casts = [
        'line_no' => 'int',
        'vat_is_derived' => 'bool',
        'gross_is_derived' => 'bool',
        'raw' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(KsefDocumentModel::class, 'ksef_document_id');
    }

    public function product()
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }
}
