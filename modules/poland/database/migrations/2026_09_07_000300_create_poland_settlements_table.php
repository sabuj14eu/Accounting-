<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A settlement that was computed and shown to the taxpayer.
 *
 * The full report JSON is kept verbatim, including the rate-table stamps it was
 * computed from. Rates change; a settlement must stay reproducible as it stood
 * on the day it was made, and recomputing it later from current tables would
 * quietly rewrite history.
 *
 * `filed_at` and `filing_reference` are null until an actual submission
 * succeeds. Nothing else in the system may report a settlement as filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('period', 7)->index();
            $table->decimal('gross_sales', 14, 2);
            $table->decimal('revenue_for_income_tax', 14, 2);
            $table->decimal('zus_total', 14, 2);
            $table->decimal('zus_social', 14, 2);
            $table->decimal('zus_health', 14, 2);
            $table->decimal('vat_due', 14, 2);
            $table->decimal('pit_due', 14, 2);
            $table->decimal('total_due', 14, 2);
            $table->boolean('is_estimate')->default(false);
            $table->json('report');
            $table->json('rate_sources');
            $table->timestamp('computed_at');

            // Filing is a separate, later fact — never inferred from the calculation.
            $table->timestamp('filed_at')->nullable();
            $table->string('filing_reference')->nullable();
            $table->string('filing_channel', 40)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_settlements');
    }
};
