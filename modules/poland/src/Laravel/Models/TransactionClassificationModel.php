<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Poland\Reconciliation\MatchQuality;
use Poland\Reconciliation\TransactionCategory;

class TransactionClassificationModel extends Model
{
    protected $table = 'pl_transaction_classifications';

    protected $guarded = [];

    protected $casts = [
        'confidence' => 'float',
        'auto_bookable' => 'bool',
        'decided_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(BankTransactionModel::class, 'transaction_id');
    }

    /** Everything a person still has to look at. */
    public function scopeNeedsReview(Builder $query): Builder
    {
        return $query->where('decision', 'pending')
            ->where(function (Builder $q): void {
                $q->where('category', TransactionCategory::NeedsReview->value)
                    ->orWhere('match_quality', MatchQuality::Possible->value);
            });
    }

    public function categoryEnum(): TransactionCategory
    {
        return TransactionCategory::from($this->category);
    }

    public function qualityEnum(): MatchQuality
    {
        return MatchQuality::from($this->match_quality);
    }

    public function isDecided(): bool
    {
        return $this->decision !== 'pending';
    }
}
