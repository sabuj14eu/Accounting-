<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/** One incremental synchronisation run and what it did. */
class KsefSyncRunModel extends Model
{
    protected $table = 'pl_ksef_sync_runs';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'window_from' => 'datetime',
        'window_to' => 'datetime',
        'report' => 'array',
    ];
}
