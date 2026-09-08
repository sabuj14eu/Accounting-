<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/** A classified KSeF failure, safe to display: no token ever reaches this table. */
class KsefErrorModel extends Model
{
    protected $table = 'pl_ksef_errors';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'retryable' => 'bool',
        'outcome_known' => 'bool',
        'details' => 'array',
        'occurred_at' => 'datetime',
    ];
}
