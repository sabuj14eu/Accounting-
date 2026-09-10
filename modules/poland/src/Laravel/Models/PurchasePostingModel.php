<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One approved invoice's entry in the purchase register.
 *
 * Append-only in effect: the only update the model permits is recording what
 * reversed it. Any change of amount, period or category is a new posting with
 * a reason, and both rows stay. Deleting is refused outright.
 */
class PurchasePostingModel extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    private const MUTABLE = ['status', 'reversed_by_posting_id', 'updated_at'];

    protected $table = 'pl_purchase_postings';

    protected $guarded = [];

    protected $casts = [
        'by_rate' => 'array',
        'plan' => 'array',
        'posted_at' => 'datetime',
        'deductible_share' => 'float',
        'kpir_column' => 'int',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $model): void {
            $illegal = array_diff(array_keys($model->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new RuntimeException(sprintf(
                    'Księgowanie zakupu jest niezmienne (próba zmiany: %s). Zmiana kwoty, okresu lub '
                    .'kategorii to odwrócenie i nowe księgowanie z przyczyną — oba wpisy zostają.',
                    implode(', ', $illegal),
                ));
            }
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Księgowań zakupu nie usuwa się. Odwróć je z podaną przyczyną.');
        });
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function document()
    {
        return $this->belongsTo(KsefDocumentModel::class, 'ksef_document_id');
    }
}
