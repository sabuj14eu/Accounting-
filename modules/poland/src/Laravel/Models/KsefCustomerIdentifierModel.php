<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The buyer's tax identifier for KSeF, confirmed by a person.
 *
 * The ERP customer record has no NIP field, and a B2B invoice with `BrakID`
 * is a wrong invoice, so the identifier is asked for once per customer,
 * recorded with who confirmed it, and reused.
 */
class KsefCustomerIdentifierModel extends Model
{
    protected $table = 'pl_ksef_customer_identifiers';

    protected $guarded = [];

    protected $casts = [
        'local_government_sub_unit' => 'bool',
        'vat_group_member' => 'bool',
    ];
}
