<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The month's payment checklist: one row per obligation.
 *
 * A row exists for ZUS, PIT and VAT every month, INCLUDING when nothing is
 * payable. "No row" and "nothing to pay" are different facts, and a checklist
 * that silently omits a line leaves the taxpayer unable to tell whether the
 * obligation was zero or was never computed.
 *
 * Unique on (profile, period, kind) so a month cannot grow two ZUS lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_payment_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('pl_settlements')->nullOnDelete();
            $table->string('period', 7)->index();
            $table->string('kind', 20);                 // zus | pit | vat
            $table->string('form', 40)->nullable();     // e.g. JPK_V7M, PIT-28
            $table->string('pay_to')->nullable();       // e.g. ZUS, Urząd Skarbowy

            $table->decimal('amount', 14, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->boolean('due_date_verified')->default(true);

            // unpaid | paid | nothing_to_pay
            $table->string('status', 20)->default('unpaid')->index();

            // A surplus is not a payment. Recorded separately so it can never be
            // rendered in the amount column.
            $table->decimal('surplus', 14, 2)->default(0);

            $table->timestamp('paid_at')->nullable();
            $table->decimal('amount_paid', 14, 2)->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['tax_profile_id', 'period', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_payment_obligations');
    }
};
