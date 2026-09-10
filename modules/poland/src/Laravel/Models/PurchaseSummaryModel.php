<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Poland\Domain\Money;
use Poland\Domain\Period;
use Poland\Domain\PurchaseRegister;

class PurchaseSummaryModel extends Model
{
    protected $table = 'pl_purchase_summaries';

    protected $guarded = [];

    protected $casts = ['document_count' => 'int', 'superseded_at' => 'datetime'];

    public function toDomain(): PurchaseRegister
    {
        return new PurchaseRegister(
            Period::parse($this->period),
            Money::parse((string) $this->deductible_costs_net),
            Money::parse((string) $this->deductible_input_vat),
            (int) $this->document_count,
            $this->note,
        );
    }
}
