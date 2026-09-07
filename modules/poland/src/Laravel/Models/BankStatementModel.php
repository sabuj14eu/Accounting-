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
        'has_balances' => 'bool',
        'imported_at' => 'datetime',
    ];

    /** Whether this statement covers a whole month, or only part of one. */
    public function isComplete(): bool
    {
        return $this->completeness === 'COMPLETE';
    }

    /**
     * True only when coverage was actually established. UNKNOWN is not
     * complete — a format that states no period cannot prove it covered one.
     */
    public function coverageKnown(): bool
    {
        return in_array($this->completeness, ['COMPLETE', 'PARTIAL', 'OUTSIDE_PERIOD'], true);
    }

    /**
     * A statement is trustworthy only if it balanced AND every row was read.
     * `null` balances mean the format carried none — unknown, not fine.
     */
    public function isTrustworthy(): bool
    {
        return ($this->problems ?? []) === [] && $this->balances_reconcile !== false;
    }
}
