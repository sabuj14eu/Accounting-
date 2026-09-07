<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Poland\Reporting\SettlementStage;

/**
 * A stored settlement, at one of three distinct stages.
 *
 * CALCULATED — an amount was derived. PREPARED — a document was built and
 * validated. FILED — it was actually submitted and the authority returned a
 * reference.
 *
 * `filed_at` is the ONLY thing in this system that means "submitted". It is set
 * by a successful submission and by nothing else. Reaching the preparation
 * stage does not set it, and neither does a complete, confident calculation.
 */
class SettlementModel extends Model
{
    protected $table = 'pl_settlements';

    protected $guarded = [];

    protected $casts = [
        'report' => 'array',
        'rate_sources' => 'array',
        'rate_provenance' => 'array',
        'is_estimate' => 'bool',
        'rates_fit_for_filing' => 'bool',
        'computed_at' => 'datetime',
        'prepared_at' => 'datetime',
        'filed_at' => 'datetime',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(PreparedDocumentModel::class, 'settlement_id');
    }

    public function stage(): SettlementStage
    {
        // Derived from the facts, not trusted from the column: a stage field
        // that can disagree with filed_at is a stage field that eventually will.
        if ($this->filed_at !== null) {
            return SettlementStage::Filed;
        }

        if ($this->prepared_at !== null) {
            return SettlementStage::Prepared;
        }

        return SettlementStage::Calculated;
    }

    public function isFiled(): bool
    {
        return $this->stage()->submitted();
    }

    /** Whether this settlement may be advanced toward filing at all. */
    public function fitForFiling(): bool
    {
        return ! $this->is_estimate && (bool) $this->rates_fit_for_filing;
    }

    /** @return list<string> */
    public function blockersToFiling(): array
    {
        $blockers = [];

        if ($this->is_estimate) {
            $blockers[] = 'Wynik jest oszacowaniem — brakuje danych do dokładnego wyliczenia.';
        }

        if (! $this->rates_fit_for_filing) {
            $blockers[] = 'Użyte stawki nie zostały potwierdzone w źródłach urzędowych.';
        }

        return $blockers;
    }

    public function statusLabel(): string
    {
        $stage = $this->stage();

        if ($stage === SettlementStage::Filed) {
            return sprintf(
                'Złożone %s (%s)',
                $this->filed_at->format('d.m.Y'),
                $this->filing_reference ?? 'bez referencji',
            );
        }

        if ($stage === SettlementStage::Prepared) {
            return 'Przygotowane '.$this->prepared_at->format('d.m.Y').' — jeszcze nie złożone';
        }

        return $this->is_estimate
            ? 'Szacunek — nie nadaje się do złożenia'
            : 'Wyliczone — nic nie zostało wysłane';
    }
}
