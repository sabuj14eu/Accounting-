<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored settlement.
 *
 * `filed_at` is the ONLY thing in this system that means "submitted". It is set
 * by a successful submission and by nothing else — no calculation, however
 * complete, may set it.
 */
class SettlementModel extends Model
{
    protected $table = 'pl_settlements';

    protected $guarded = [];

    protected $casts = [
        'report' => 'array',
        'rate_sources' => 'array',
        'is_estimate' => 'bool',
        'computed_at' => 'datetime',
        'filed_at' => 'datetime',
    ];

    public function isFiled(): bool
    {
        return $this->filed_at !== null;
    }

    public function statusLabel(): string
    {
        if ($this->isFiled()) {
            return 'Złożone '.$this->filed_at->format('d.m.Y');
        }

        return $this->is_estimate ? 'Szacunek — niezłożone' : 'Wyliczone — niezłożone';
    }
}
