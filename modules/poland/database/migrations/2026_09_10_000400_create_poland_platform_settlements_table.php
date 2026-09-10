<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage B, section 7: a delivery platform's month as an accounting source.
 *
 * Corrections supersede: a re-entered month gets version n+1 and the earlier
 * row stays with `superseded_by_id` set. `vat_treatment` is stored per row
 * because it is a decision, and `unknown` is a legal value that refuses to
 * post — recorded here, enforced in the domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_platform_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('platform', 20);                        // glovo | uber_eats | other
            $table->string('period', 7)->index();
            $table->unsignedInteger('version')->default(1);
            $table->date('statement_from')->nullable();
            $table->date('statement_to')->nullable();

            $table->decimal('gross_orders', 14, 2);
            $table->json('gross_orders_by_rate')->nullable();      // NULL = the statement gave no split
            $table->decimal('commission_net', 14, 2);
            $table->decimal('commission_vat', 14, 2);
            $table->string('commission_vat_rate', 8)->nullable();
            $table->json('other_deductions');                      // name => amount
            $table->decimal('other_deductions_total', 14, 2)->default(0);
            $table->decimal('payout_expected', 14, 2);
            $table->decimal('payout_received', 14, 2)->nullable();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('pl_bank_transactions')->nullOnDelete();
            $table->decimal('difference', 14, 2)->nullable();

            $table->string('vat_treatment', 24)->default('unknown'); // domestic_invoice | import_of_services | unknown
            $table->foreignId('commission_invoice_document_id')->nullable()
                ->constrained('pl_ksef_documents')->nullOnDelete();

            $table->string('source_type', 20)->default('manual');   // manual | statement
            $table->string('source_filename')->nullable();
            $table->string('source_checksum', 64)->nullable();

            $table->string('status', 24);                          // no_payout_recorded | reconciled | requires_review
            $table->string('row_status', 12)->default('recorded'); // recorded | superseded
            $table->foreignId('superseded_by_id')->nullable()->constrained('pl_platform_settlements')->nullOnDelete();
            $table->text('correction_reason')->nullable();

            $table->string('recorded_by');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['tax_profile_id', 'platform', 'period', 'version'], 'pl_platform_settlements_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_platform_settlements');
    }
};
