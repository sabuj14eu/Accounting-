<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReportLineModel extends Model
{
    protected $table = 'pl_sales_report_lines';

    protected $guarded = [];

    protected $casts = ['lump_sum_rate' => 'float'];

    public function report()
    {
        return $this->belongsTo(SalesReportModel::class, 'sales_report_id');
    }
}
