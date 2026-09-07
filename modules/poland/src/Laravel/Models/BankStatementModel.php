<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementModel extends Model
{
    protected $table = 'pl_bank_statements';

    protected $guarded = [];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'problems' => 'array',
        'balances_reconcile' => 'bool',
        'imported_at' => 'datetime',
    ];

    /**
     * A statement is trustworthy only if it balanced AND every row was read.
     * `null` balances mean the format carried none — unknown, not fine.
     */
    public function isTrustworthy(): bool
    {
        return ($this->problems ?? []) === [] && $this->balances_reconcile !== false;
    }
}
