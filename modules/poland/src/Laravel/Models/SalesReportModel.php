<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Poland\Domain\FiscalSalesReport;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\SalesLine;

/**
 * A recorded fiscal cash-register report.
 *
 * Status values: `recorded` (in force) and `superseded` (replaced by a
 * correction). A superseded row is never deleted and never edited — the
 * unique index tolerates it precisely because its status differs.
 */
class SalesReportModel extends Model
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $table = 'pl_sales_reports';

    protected $guarded = [];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReportLineModel::class, 'sales_report_id');
    }

    public function profile()
    {
        return $this->belongsTo(TaxProfileModel::class, 'tax_profile_id');
    }

    public function scopeInForce(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RECORDED);
    }

    public function toDomain(): FiscalSalesReport
    {
        $lines = $this->lines->map(static fn (SalesReportLineModel $line): SalesLine => new SalesLine(
            $line->designation,
            Money::parse((string) $line->gross),
            $line->lump_sum_rate !== null ? (float) $line->lump_sum_rate : null,
            $line->note,
            (string) ($line->channel ?? \Poland\Domain\SalesChannel::SHOP_REGISTER),
        ))->all();

        return FiscalSalesReport::of(
            Period::parse($this->period),
            array_values($lines),
            $this->register_id,
            $this->report_number,
            $this->note,
        );
    }
}
