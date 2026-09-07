<?php

declare(strict_types=1);

namespace Poland\Laravel\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Poland\Banking\BankTransaction;
use Poland\Domain\Money;

class BankTransactionModel extends Model
{
    protected $table = 'pl_bank_transactions';

    protected $guarded = [];

    protected $casts = [
        'booking_date' => 'date',
        'value_date' => 'date',
        'raw' => 'array',
    ];

    public function statement()
    {
        return $this->belongsTo(BankStatementModel::class, 'statement_id');
    }

    public function duplicateOf()
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of');
    }

    /** Flagged as possibly the same payment, and nobody has decided yet. */
    public function isUnresolvedDuplicate(): bool
    {
        return $this->possible_duplicate_of !== null && $this->duplicate_decision === 'pending';
    }

    public function classification()
    {
        return $this->hasOne(TransactionClassificationModel::class, 'transaction_id');
    }

    public function toDomain(): BankTransaction
    {
        return new BankTransaction(
            bookingDate: new DateTimeImmutable($this->booking_date->format('Y-m-d')),
            amount: Money::parse((string) $this->amount),
            description: (string) $this->description,
            valueDate: $this->value_date !== null
                ? new DateTimeImmutable($this->value_date->format('Y-m-d'))
                : null,
            counterparty: $this->counterparty,
            counterpartyAccount: $this->counterparty_account,
            reference: $this->reference,
            balanceAfter: $this->balance_after !== null
                ? Money::parse((string) $this->balance_after)
                : null,
            currency: $this->currency,
            raw: $this->raw ?? [],
        );
    }
}
