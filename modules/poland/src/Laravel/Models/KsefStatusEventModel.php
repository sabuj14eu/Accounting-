<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** One state transition of a submission or an incoming document. Append-only. */
class KsefStatusEventModel extends Model
{
    protected $table = 'pl_ksef_status_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Historia statusów KSeF jest tylko do dopisywania.');
        });
        static::deleting(static function (): never {
            throw new RuntimeException('Historia statusów KSeF jest tylko do dopisywania.');
        });
    }
}
