<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KSeF: credentials, sync state and downloaded invoices.
 *
 * The KSeF number is uniquely indexed per taxpayer. That index — not
 * application logic — is what makes a duplicate import impossible, because the
 * logic that should have prevented it is exactly the thing that fails during a
 * retry after a timeout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_ksef_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('nip', 20);
            $table->string('base_url');

            // Encrypted at rest by the model's `encrypted` cast, never logged,
            // never rendered, and excluded from the model's array form.
            $table->text('token_encrypted')->nullable();

            // InvoiceRead only. Stored so a widened scope is visible in the data,
            // not just in whatever the API happens to accept.
            $table->string('scope', 40)->default('InvoiceRead');

            $table->timestamp('token_valid_until')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'environment']);
        });

        Schema::create('pl_ksef_sync_state', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('environment', 20);

            // Incremental retrieval: the high-water mark, and the cursor within
            // it, so an interrupted run resumes instead of restarting or skipping.
            $table->timestamp('synced_through')->nullable();
            $table->string('cursor')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('last_run_completed')->default(true);
            $table->text('last_run_summary')->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'environment']);
        });

        Schema::create('pl_ksef_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();

            $table->string('ksef_number', 64);
            $table->string('invoice_number')->nullable();
            $table->string('invoice_type', 20)->nullable();
            $table->string('direction', 12);              // incoming | outgoing
            $table->date('invoice_date')->nullable();
            $table->date('sale_date')->nullable();
            $table->timestamp('permanent_storage_date')->nullable();
            $table->timestamp('retrieved_at');

            $table->string('seller_nip', 20)->nullable();
            $table->string('seller_name')->nullable();
            $table->string('buyer_nip', 20)->nullable();
            $table->string('buyer_name')->nullable();

            $table->decimal('net', 14, 2)->nullable();
            $table->decimal('vat', 14, 2)->nullable();
            $table->decimal('gross', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('ksef_status')->nullable();

            // The original XML, kept verbatim and forever. It is the document of
            // record; everything parsed out of it is derived and re-derivable.
            $table->longText('original_xml');
            $table->string('xml_checksum', 64);
            $table->json('metadata');
            $table->json('parse_result');
            $table->json('missing_fields')->nullable();

            $table->string('period', 7)->nullable()->index();
            $table->string('processing_status', 24)->default('imported');
            $table->boolean('needs_review')->default(false);
            $table->text('review_reason')->nullable();

            $table->timestamps();

            $table->unique(['tax_profile_id', 'ksef_number']);
            $table->index(['tax_profile_id', 'direction', 'invoice_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_ksef_documents');
        Schema::dropIfExists('pl_ksef_sync_state');
        Schema::dropIfExists('pl_ksef_credentials');
    }
};
