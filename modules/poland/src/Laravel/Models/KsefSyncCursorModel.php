<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Poland\Ksef\Incoming\SyncCursorState;

/** Persisted {@see SyncCursorState}, one row per (profile, environment, subject type). */
class KsefSyncCursorModel extends Model
{
    protected $table = 'pl_ksef_sync_cursors';

    protected $guarded = [];

    protected $casts = [
        'synced_through' => 'datetime',
        'window_from' => 'datetime',
        'window_to' => 'datetime',
        'in_progress' => 'bool',
        'last_run_at' => 'datetime',
        'last_run_completed' => 'bool',
    ];

    public function toState(): SyncCursorState
    {
        return new SyncCursorState(
            $this->synced_through?->toDateTimeImmutable(),
            $this->window_from?->toDateTimeImmutable(),
            $this->window_to?->toDateTimeImmutable(),
            (int) $this->page_offset,
            (bool) $this->in_progress,
        );
    }

    /** @return array<string,mixed> */
    public static function attributesFor(SyncCursorState $state): array
    {
        return [
            'synced_through' => $state->syncedThrough,
            'window_from' => $state->windowFrom,
            'window_to' => $state->windowTo,
            'page_offset' => $state->pageOffset,
            'in_progress' => $state->inProgress,
        ];
    }
}
