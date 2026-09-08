<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An outgoing FA(3) exactly as it was (or will be) sent, or a UPO exactly as
 * KSeF returned it. The XML and its hash never change after insert: a
 * regenerated document is a NEW row, and the submission points at the one
 * that actually left.
 */
class KsefInvoiceDocumentModel extends Model
{
    public const KIND_FA3 = 'fa3_outgoing';

    public const KIND_UPO = 'upo';

    protected $table = 'pl_ksef_invoice_documents';

    protected $guarded = [];

    protected $casts = [
        'generated_at' => 'datetime',
        'validated_at' => 'datetime',
        'validation_errors' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $model): void {
            foreach (['xml', 'xml_hash', 'xml_hash_base64', 'xml_size', 'kind', 'generated_at'] as $frozen) {
                if ($model->isDirty($frozen)) {
                    throw new RuntimeException(sprintf(
                        'Dokument KSeF jest niezmienny (pole %s). Nowa wersja XML to nowy wiersz, nigdy edycja.',
                        $frozen,
                    ));
                }
            }
        });
        static::deleting(static function (): never {
            throw new RuntimeException('Dokumentów KSeF nie usuwa się — są dowodem tego, co wysłano i co odebrano.');
        });
    }

    public function verifyHash(): bool
    {
        return hash('sha256', (string) $this->xml) === $this->xml_hash;
    }

    public function isValid(): bool
    {
        return $this->validation_status === 'VALID';
    }
}
