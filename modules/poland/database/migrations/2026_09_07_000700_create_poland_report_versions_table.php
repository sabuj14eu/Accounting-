<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable snapshots of every monthly report ever generated.
 *
 * `pl_settlements` holds the CURRENT state of a month. This table holds every
 * version that was ever shown to anybody, append-only, with the checksum of its
 * content and the rate versions behind it.
 *
 * The requirement it exists for: a monthly accounting report must not silently
 * disappear or change after generation. If a month is recomputed — because a
 * correction arrived, or a rate was verified — the earlier report does not
 * vanish. It stays, numbered, and the new one is version n+1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_report_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('pl_settlements')->nullOnDelete();
            $table->string('period', 7)->index();
            $table->unsignedInteger('version');

            $table->json('report');
            $table->json('rate_provenance');
            $table->string('checksum', 64);

            $table->decimal('total_due', 14, 2);
            $table->boolean('is_estimate')->default(false);
            $table->boolean('rates_fit_for_filing')->default(false);

            $table->string('generated_by')->nullable();
            $table->timestamp('generated_at');

            // A closed month is frozen: recomputing it requires an explicit reopen
            // with a reason, which is itself audited.
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->text('reopen_reason')->nullable();

            $table->unique(['tax_profile_id', 'period', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_report_versions');
    }
};
