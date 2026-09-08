<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Poland\Domain\Money;
use RuntimeException;

/**
 * An invoice downloaded from KSeF.
 *
 * The original XML is immutable once stored: KSeF is the document of record and
 * an invoice that could be edited here would stop matching the authority's copy.
 * Everything derived from it may be recomputed; the XML itself may not change.
 */
class KsefDocumentModel extends Model
{
    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

    protected $table = 'pl_ksef_documents';

    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
        'sale_date' => 'date',
        'permanent_storage_date' => 'datetime',
        'retrieved_at' => 'datetime',
        'metadata' => 'array',
        'parse_result' => 'array',
        'missing_fields' => 'array',
        'needs_review' => 'bool',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $model): void {
            // Filling in an XML that was missing (fetched on a later run) is
            // allowed; replacing an XML that exists is not.
            $xmlReplaced = $model->isDirty('original_xml') && $model->getOriginal('original_xml') !== null;
            if ($xmlReplaced || $model->isDirty('ksef_number')) {
                throw new RuntimeException(
                    'Oryginalny XML faktury i numer KSeF są niezmienne. KSeF jest dokumentem '
                    .'źródłowym — zmiana tutaj rozjechałaby naszą kopię z kopią organu.',
                );
            }
        });
    }

    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_INCOMING);
    }

    public function scopeNeedingReview(Builder $query): Builder
    {
        return $query->where('needs_review', true);
    }

    public function netMoney(): ?Money
    {
        return $this->net === null ? null : Money::parse((string) $this->net);
    }

    public function vatMoney(): ?Money
    {
        return $this->vat === null ? null : Money::parse((string) $this->vat);
    }

    public function grossMoney(): ?Money
    {
        return $this->gross === null ? null : Money::parse((string) $this->gross);
    }

    public function verifyXmlChecksum(): ?bool
    {
        if ($this->original_xml === null || $this->xml_checksum === null) {
            return null; // nothing to verify: the body was never fetched
        }

        return hash('sha256', (string) $this->original_xml) === $this->xml_checksum;
    }

    public function hasXml(): bool
    {
        return $this->original_xml !== null && $this->original_xml !== '';
    }

    public function events()
    {
        return $this->hasMany(KsefStatusEventModel::class, 'ksef_document_id')->orderBy('occurred_at');
    }

    public function isCorrection(): bool
    {
        return $this->invoice_type !== null && str_contains(strtoupper($this->invoice_type), 'KOR');
    }
}
