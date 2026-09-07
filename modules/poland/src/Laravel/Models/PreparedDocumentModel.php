<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A filing document that was built and validated, and possibly submitted.
 *
 * `idempotency_key` is uniquely indexed in the database rather than checked in
 * application code: a retry after a network timeout must be unable to file a
 * second copy even if the application logic that should have prevented it is
 * the thing that failed.
 */
class PreparedDocumentModel extends Model
{
    protected $table = 'pl_prepared_documents';

    protected $guarded = [];

    protected $casts = [
        'rule_versions' => 'array',
        'validation_errors' => 'array',
        'submission_response' => 'array',
        'validated' => 'bool',
        'prepared_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function settlement()
    {
        return $this->belongsTo(SettlementModel::class, 'settlement_id');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null
            && $this->submission_reference !== null
            && trim((string) $this->submission_reference) !== '';
    }

    /** Validated with no errors — not merely "no errors recorded". */
    public function isValid(): bool
    {
        return $this->validated && ($this->validation_errors ?? []) === [];
    }
}
