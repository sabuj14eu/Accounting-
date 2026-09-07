<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The taxpayer's configuration. Every field here changes the tax owed, so the
 * table is deliberately explicit rather than a JSON blob: a regime or a ZUS
 * scheme has to be queryable and auditable, not buried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name');
            $table->string('nip', 20)->nullable();
            $table->string('regon', 20)->nullable();

            $table->string('pit_regime', 20);
            $table->decimal('lump_sum_rate', 6, 4)->nullable();

            $table->string('vat_status', 30);
            $table->string('vat_settlement', 20)->default('monthly');

            $table->string('zus_scheme', 30);
            $table->boolean('sickness_insurance')->default(true);
            $table->decimal('accident_rate', 6, 4)->nullable();
            $table->decimal('maly_zus_plus_base', 14, 2)->nullable();

            $table->string('business_started_at', 7);
            $table->unsignedTinyInteger('business_started_on_day')->default(1);

            $table->string('deduction_basis', 30)->default('accrued_for_month');
            $table->boolean('health_band_from_previous_year')->default(false);
            $table->decimal('previous_year_revenue', 14, 2)->nullable();
            $table->boolean('reduce_health_band_by_social')->default(true);
            $table->json('cash_register_letters')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_tax_profiles');
    }
};
