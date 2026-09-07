<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only. Updates and deletes are refused at the model level so that a
 * stray mass-assignment or a well-meaning cleanup job cannot rewrite history.
 */
class AuditEventModel extends Model
{
    protected $table = 'pl_audit_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Audit events are append-only and cannot be modified.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Audit events are append-only and cannot be deleted.');
        });
    }
}
