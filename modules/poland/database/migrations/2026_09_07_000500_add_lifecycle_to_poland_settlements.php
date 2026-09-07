<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the settlement lifecycle into calculation, preparation and filing.
 *
 * Before this, a settlement had a computed_at and a filed_at, which left
 * "prepared a JPK but sent nothing" with nowhere to live — and anything without
 * a place to live ends up recorded as the nearest stage that has one, which
 * here would have meant filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pl_settlements', function (Blueprint $table): void {
            $table->string('stage', 20)->default('calculated')->after('is_estimate')->index();

            // Whether the figures may be advanced to filing at all: complete
            // inputs AND rates verified against official sources.
            $table->boolean('rates_fit_for_filing')->default(false)->after('is_estimate');
            $table->json('rate_provenance')->nullable()->after('rate_sources');

            $table->timestamp('prepared_at')->nullable()->after('computed_at');
        });

        Schema::create('pl_prepared_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('settlement_id')->constrained('pl_settlements')->cascadeOnDelete();
            $table->string('channel', 40)->index();
            $table->string('period', 7)->index();
            $table->string('schema_structure', 40);
            $table->string('schema_version', 40);
            $table->string('content_type', 80);
            $table->longText('content');
            $table->string('checksum', 64)->index();
            $table->json('rule_versions');
            $table->boolean('validated')->default(false);
            $table->json('validation_errors')->nullable();

            // Idempotency is enforced by the database, not by application flow.
            // A retry after a timeout must not be able to file a second copy.
            $table->string('idempotency_key')->nullable()->unique();

            $table->timestamp('prepared_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('submission_reference')->nullable();
            $table->json('submission_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_prepared_documents');

        Schema::table('pl_settlements', function (Blueprint $table): void {
            $table->dropColumn(['stage', 'rates_fit_for_filing', 'rate_provenance', 'prepared_at']);
        });
    }
};
