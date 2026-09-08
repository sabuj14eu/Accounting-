<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Poland\Ksef\Outgoing\KsefSubmissionState;

/**
 * The local record of sending one accounting invoice to KSeF.
 *
 * The state column is the local lifecycle; ksef_status_* is what KSeF said,
 * kept beside it, never instead of it. The accounting invoice itself is not
 * touched by anything here.
 */
class KsefSubmissionModel extends Model
{
    protected $table = 'pl_ksef_submissions';

    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'date',
        'ksef_status_details' => 'array',
        'submitted_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'acquisition_date' => 'datetime',
        'permanent_storage_date' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function stateEnum(): KsefSubmissionState
    {
        return KsefSubmissionState::from((string) $this->state);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KsefInvoiceDocumentModel::class, 'document_id');
    }

    public function upoDocument(): BelongsTo
    {
        return $this->belongsTo(KsefInvoiceDocumentModel::class, 'upo_document_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(KsefStatusEventModel::class, 'submission_id')->orderBy('occurred_at')->orderBy('id');
    }

    public function lastError(): BelongsTo
    {
        return $this->belongsTo(KsefErrorModel::class, 'last_error_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TaxProfileModel::class, 'tax_profile_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('state', [KsefSubmissionState::Submitted->value, KsefSubmissionState::Processing->value]);
    }

    public function scopeNeedingReview(Builder $query): Builder
    {
        return $query->where('state', KsefSubmissionState::ManualReview->value);
    }
}
