<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bank statements, their transactions, and how each was classified. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('filename');
            $table->string('format', 20);
            $table->string('account_number')->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('opening_balance', 16, 2)->nullable();
            $table->decimal('closing_balance', 16, 2)->nullable();

            // Whether the transactions add up from opening to closing. A false
            // here means rows were dropped, and a partial statement silently
            // understates costs.
            $table->boolean('balances_reconcile')->nullable();
            $table->json('problems')->nullable();
            $table->unsignedInteger('transaction_count')->default(0);
            $table->string('checksum', 64);
            $table->timestamp('imported_at');
            $table->string('imported_by')->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'checksum']);
        });

        Schema::create('pl_bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->foreignId('statement_id')->nullable()->constrained('pl_bank_statements')->nullOnDelete();

            $table->date('booking_date')->index();
            $table->date('value_date')->nullable();
            $table->decimal('amount', 16, 2);
            $table->string('direction', 8);
            $table->text('description');
            $table->string('counterparty')->nullable();
            $table->string('counterparty_account')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('balance_after', 16, 2)->nullable();
            $table->string('currency', 3)->default('PLN');

            // Re-importing the same statement must not double the month's costs.
            $table->string('fingerprint', 64);

            $table->string('period', 7)->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'fingerprint']);
        });

        Schema::create('pl_transaction_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('pl_bank_transactions')->cascadeOnDelete();

            $table->string('category', 40);
            $table->decimal('confidence', 4, 3);
            $table->text('reason');
            $table->string('match_quality', 20);
            $table->string('matched_document')->nullable();
            $table->string('matched_document_type', 40)->nullable();

            // A suggestion is not a decision. Nothing is booked until a person
            // accepts it, or until it qualified as an exact automatic match.
            $table->boolean('auto_bookable')->default(false);
            $table->string('decision', 20)->default('pending'); // pending|accepted|rejected
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->unique('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_transaction_classifications');
        Schema::dropIfExists('pl_bank_transactions');
        Schema::dropIfExists('pl_bank_statements');
    }
};
