<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Poland\Government\DocumentAction;
use Poland\Government\GovernmentAuthority;

class GovernmentDocumentModel extends Model
{
    protected $table = 'pl_government_documents';

    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'issue_date' => 'date',
        'payment_deadline' => 'date',
        'response_deadline' => 'date',
        'resolved_at' => 'datetime',
        'extracted' => 'array',
        'text_unavailable' => 'bool',
        'needs_manual_review' => 'bool',
    ];

    protected $hidden = ['extracted_text'];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'new');
    }

    public function scopeUrgent(Builder $query): Builder
    {
        return $query->whereIn('action', [
            DocumentAction::PaymentRequired->value,
            DocumentAction::ResponseRequired->value,
            DocumentAction::PossibleIssue->value,
        ]);
    }

    public function actionEnum(): DocumentAction
    {
        return DocumentAction::from($this->action);
    }

    public function authorityEnum(): GovernmentAuthority
    {
        return GovernmentAuthority::from($this->authority);
    }

    /** The nearest deadline of either kind — the one that actually matters. */
    public function nextDeadline(): ?\Illuminate\Support\Carbon
    {
        $dates = array_filter([$this->payment_deadline, $this->response_deadline]);
        if ($dates === []) {
            return null;
        }

        usort($dates, static fn ($a, $b): int => $a <=> $b);

        return $dates[0];
    }
}
