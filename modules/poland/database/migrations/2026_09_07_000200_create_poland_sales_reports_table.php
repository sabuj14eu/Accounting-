<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fiscal cash register's monthly report, stored as entered.
 *
 * A unique key on (profile, period) makes a duplicated month impossible rather
 * than merely discouraged: two reports for August would silently double a
 * year's cumulative revenue and every advance after it.
 *
 * Corrections never overwrite. A superseded report keeps its row and points at
 * the one that replaced it, so the ledger can always be replayed as it stood.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_sales_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('period', 7)->index();
            $table->string('register_id')->nullable();
            $table->string('report_number')->nullable();
            $table->decimal('gross_total', 14, 2);
            $table->decimal('net_total', 14, 2);
            $table->decimal('vat_total', 14, 2);
            $table->string('status', 20)->default('recorded');
            $table->foreignId('superseded_by_id')->nullable()->constrained('pl_sales_reports')->nullOnDelete();
            $table->text('correction_reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'period', 'status'], 'pl_sales_reports_active_period');
        });

        Schema::create('pl_sales_report_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_report_id')->constrained('pl_sales_reports')->cascadeOnDelete();
            $table->string('designation', 10);
            $table->decimal('gross', 14, 2);
            $table->decimal('net', 14, 2);
            $table->decimal('vat', 14, 2);
            $table->decimal('lump_sum_rate', 6, 4)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('pl_purchase_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('period', 7)->index();
            $table->decimal('deductible_costs_net', 14, 2)->default(0);
            $table->decimal('deductible_input_vat', 14, 2)->default(0);
            $table->unsignedInteger('document_count')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_purchase_summaries');
        Schema::dropIfExists('pl_sales_report_lines');
        Schema::dropIfExists('pl_sales_reports');
    }
};
