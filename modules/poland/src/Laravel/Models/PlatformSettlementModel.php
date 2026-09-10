<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Platforms\Platform;
use Poland\Platforms\PlatformSettlement;
use Poland\Platforms\VatTreatment;

/** A stored platform month. Corrections supersede; rows are never edited in place. */
class PlatformSettlementModel extends Model
{
    public const ROW_RECORDED = 'recorded';

    public const ROW_SUPERSEDED = 'superseded';

    protected $table = 'pl_platform_settlements';

    protected $guarded = [];

    protected $casts = [
        'gross_orders_by_rate' => 'array',
        'other_deductions' => 'array',
        'statement_from' => 'date',
        'statement_to' => 'date',
        'recorded_at' => 'datetime',
        'version' => 'int',
    ];

    public function scopeInForce(Builder $query): Builder
    {
        return $query->where('row_status', self::ROW_RECORDED);
    }

    public function toDomain(): PlatformSettlement
    {
        $byRate = null;
        if (is_array($this->gross_orders_by_rate)) {
            $byRate = [];
            foreach ($this->gross_orders_by_rate as $designation => $amount) {
                $byRate[(string) $designation] = Money::parse((string) $amount);
            }
        }

        $deductions = [];
        foreach ((array) $this->other_deductions as $name => $amount) {
            $deductions[(string) $name] = Money::parse((string) $amount);
        }

        return new PlatformSettlement(
            Platform::from($this->platform),
            Period::parse($this->period),
            Money::parse((string) $this->gross_orders),
            $byRate,
            Money::parse((string) $this->commission_net),
            Money::parse((string) $this->commission_vat),
            $deductions,
            VatTreatment::from($this->vat_treatment),
            $this->payout_received === null ? null : Money::parse((string) $this->payout_received),
        );
    }
}
