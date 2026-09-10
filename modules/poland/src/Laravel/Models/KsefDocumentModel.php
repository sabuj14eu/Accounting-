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
        'due_date' => 'date',
        'service_period_from' => 'date',
        'service_period_to' => 'date',
        'paid_on_invoice' => 'bool',
        'payment_terms_json' => 'array',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $model): void {
            if ($model->isDirty('original_xml') || $model->isDirty('ksef_number')) {
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

    /** Incoming invoices a person has not decided on yet. */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('approval_status', \Poland\Purchases\ApprovalStatus::AwaitingReview->value);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('approval_status', \Poland\Purchases\ApprovalStatus::Posted->value);
    }

    public function approvalStatus(): \Poland\Purchases\ApprovalStatus
    {
        return \Poland\Purchases\ApprovalStatus::tryFrom((string) $this->approval_status)
            ?? \Poland\Purchases\ApprovalStatus::Imported;
    }

    public function lines()
    {
        return $this->hasMany(PurchaseInvoiceLineModel::class, 'ksef_document_id')->orderBy('line_no');
    }

    public function posting()
    {
        return $this->hasOne(PurchasePostingModel::class, 'ksef_document_id');
    }

    public function correctedDocument()
    {
        return $this->belongsTo(self::class, 'corrects_document_id');
    }

    public function hasUndecidedDuplicate(): bool
    {
        return $this->possible_duplicate_of !== null && ($this->duplicate_decision ?? 'pending') === 'pending';
    }

    /** For the buyer, receipt is the moment KSeF assigned the number; failing that, when we fetched it. */
    public function receivedAt(): \DateTimeImmutable
    {
        $at = $this->permanent_storage_date ?? $this->retrieved_at;

        return \DateTimeImmutable::createFromInterface($at);
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

    public function verifyXmlChecksum(): bool
    {
        return hash('sha256', $this->original_xml) === $this->xml_checksum;
    }

    public function isCorrection(): bool
    {
        return $this->invoice_type !== null && str_contains(strtoupper($this->invoice_type), 'KOR');
    }
}
