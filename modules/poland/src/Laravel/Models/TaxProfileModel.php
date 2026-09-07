<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Poland\Domain\TaxProfile;
use Poland\Support\ProfileFactory;

/**
 * @property string $name
 * @property string|null $nip
 */
class TaxProfileModel extends Model
{
    protected $table = 'pl_tax_profiles';

    protected $guarded = [];

    protected $casts = [
        'lump_sum_rate' => 'float',
        'accident_rate' => 'float',
        'sickness_insurance' => 'bool',
        'health_band_from_previous_year' => 'bool',
        'reduce_health_band_by_social' => 'bool',
        'business_started_on_day' => 'int',
        'cash_register_letters' => 'array',
    ];

    public function salesReports(): HasMany
    {
        return $this->hasMany(SalesReportModel::class, 'tax_profile_id');
    }

    public function purchaseSummaries(): HasMany
    {
        return $this->hasMany(PurchaseSummaryModel::class, 'tax_profile_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SettlementModel::class, 'tax_profile_id');
    }

    /** Translate the stored row into the framework-free domain object. */
    public function toDomain(): TaxProfile
    {
        return ProfileFactory::fromArray(array_filter([
            'name' => $this->name,
            'nip' => $this->nip,
            'pit_regime' => $this->pit_regime,
            'lump_sum_rate' => $this->lump_sum_rate,
            'vat_status' => $this->vat_status,
            'vat_settlement' => $this->vat_settlement,
            'zus_scheme' => $this->zus_scheme,
            'sickness_insurance' => $this->sickness_insurance,
            'accident_rate' => $this->accident_rate,
            'maly_zus_plus_base' => $this->maly_zus_plus_base,
            'business_started_at' => $this->business_started_at,
            'business_started_on_day' => $this->business_started_on_day,
            'deduction_basis' => $this->deduction_basis,
            'health_band_from_previous_year' => $this->health_band_from_previous_year,
            'previous_year_revenue' => $this->previous_year_revenue,
            'reduce_health_band_by_social' => $this->reduce_health_band_by_social,
            'cash_register_letters' => $this->cash_register_letters,
        ], static fn ($v): bool => $v !== null));
    }
}
