<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An immutable snapshot of a monthly report as it was generated.
 *
 * Append-only, like the audit trail and for the same reason: a monthly
 * accounting report that can be edited after the fact proves nothing, and one
 * that can silently change is worse than one that is missing.
 *
 * Recomputing a month does not modify the previous version — it writes version
 * n+1 and leaves n exactly where it was.
 */
class ReportVersionModel extends Model
{
    protected $table = 'pl_report_versions';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'report' => 'array',
        'rate_provenance' => 'array',
        'is_estimate' => 'bool',
        'rates_fit_for_filing' => 'bool',
        'is_closed' => 'bool',
        'generated_at' => 'datetime',
        'closed_at' => 'datetime',
        'version' => 'int',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $model): void {
            // Closing a month and reopening it are the only permitted changes,
            // and each is audited by the caller.
            $permitted = ['is_closed', 'closed_at', 'reopen_reason'];
            $changed = array_keys($model->getDirty());

            if (array_diff($changed, $permitted) !== []) {
                throw new RuntimeException(
                    'Wygenerowany raport miesięczny jest niezmienny. Ponowne przeliczenie tworzy '
                    .'nową wersję, nie modyfikuje poprzedniej.',
                );
            }
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Raporty miesięczne są tylko do dopisywania — nie można ich usuwać.');
        });
    }

    public function verifyChecksum(): bool
    {
        return hash('sha256', json_encode($this->report, JSON_THROW_ON_ERROR)) === $this->checksum;
    }
}
