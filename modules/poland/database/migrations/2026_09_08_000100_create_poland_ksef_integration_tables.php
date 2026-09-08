<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KSeF 2.0 / FA(3) integration: settings, authentication sessions, outgoing
 * submissions with their immutable documents and status history, incremental
 * sync cursors and runs, classified errors, and the buyer identifiers the
 * ERP does not carry.
 *
 * Idempotency lives in the database: `pl_ksef_submissions.active_key` is
 * UNIQUE while a submission is live (NULL once rejected or cancelled), so a
 * second live submission of the same invoice is refused by the index, not by
 * a check-then-insert that a retry can race past. `invoice_reference` and
 * `ksef_number` are UNIQUE too: one KSeF identity, one row.
 *
 * Nothing here is ever updated destructively: documents and status events are
 * append-only at the model level; a submission's history is its events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pl_ksef_credentials', function (Blueprint $table): void {
            $table->string('token_fingerprint', 16)->nullable()->after('token_encrypted');
            $table->string('token_reference', 64)->nullable()->after('token_fingerprint');
            $table->json('token_permissions')->nullable()->after('scope');
            $table->json('observed_permissions')->nullable()->after('token_permissions');
            $table->string('seller_address_country', 2)->default('PL');
            $table->string('seller_address_line1', 512)->nullable();
            $table->string('seller_address_line2', 512)->nullable();
            $table->string('seller_email')->nullable();
            $table->string('seller_phone', 16)->nullable();
            $table->string('issue_place', 256)->nullable();
            $table->string('exemption_legal_basis', 256)->nullable();
            $table->string('default_payment_form', 1)->nullable();
            $table->string('bank_account', 34)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('system_info', 256)->nullable();
            $table->string('api_version', 16)->nullable();
            $table->unsignedTinyInteger('setup_step')->default(0);
            $table->timestamp('last_connection_test_at')->nullable();
            $table->boolean('last_connection_ok')->nullable();
            $table->text('last_connection_detail')->nullable();
            $table->timestamp('last_auth_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->boolean('last_sync_ok')->nullable();
            $table->timestamp('production_enabled_at')->nullable();
            $table->string('production_enabled_by')->nullable();
        });

        Schema::table('pl_ksef_documents', function (Blueprint $table): void {
            $table->string('subject_type', 20)->nullable()->after('direction');
            $table->unsignedBigInteger('sync_run_id')->nullable()->index()->after('subject_type');
            $table->string('invoice_hash_base64', 44)->nullable()->after('xml_checksum');
            $table->timestamp('acquisition_date')->nullable()->after('permanent_storage_date');
            $table->timestamp('invoicing_date')->nullable()->after('acquisition_date');
            $table->string('form_system_code', 16)->nullable();
            $table->string('form_schema_version', 8)->nullable();
            // "KSeF listed it, the body could not be fetched" is a stated fact,
            // not an empty string.
            $table->text('xml_missing_reason')->nullable();
            $table->longText('original_xml')->nullable()->change();
            $table->string('xml_checksum', 64)->nullable()->change();
        });

        Schema::create('pl_ksef_auth_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('pl_ksef_credentials')->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('reference_number', 64)->unique();
            // Encrypted at rest by the model's `encrypted` cast; never rendered.
            $table->text('access_token_encrypted');
            $table->timestamp('access_token_valid_until');
            $table->text('refresh_token_encrypted');
            $table->timestamp('refresh_token_valid_until');
            $table->string('authentication_method', 40)->nullable();
            $table->json('permissions')->nullable();
            $table->string('status', 16)->default('active');   // active | expired | revoked
            $table->timestamp('authenticated_at');
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['credential_id', 'status']);
        });

        Schema::create('pl_ksef_invoice_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('kind', 24);                      // fa3_outgoing | upo
            // The exact bytes generated or received. Immutable.
            $table->longText('xml');
            $table->char('xml_hash', 64);
            $table->string('xml_hash_base64', 44);
            $table->unsignedInteger('xml_size');
            $table->string('schema_system_code', 16)->nullable();
            $table->string('schema_version', 8)->nullable();
            $table->timestamp('generated_at');
            $table->string('validation_status', 16)->default('NOT_VALIDATED');  // VALID | INVALID | NOT_VALIDATED
            $table->timestamp('validated_at')->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('generator_version', 32)->nullable();
            $table->timestamps();

            $table->index(['tax_profile_id', 'kind']);
            $table->index('xml_hash');
        });

        Schema::create('pl_ksef_errors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tax_profile_id')->nullable()->index();
            $table->string('environment', 20);
            $table->string('operation', 64);
            $table->string('category', 32);
            $table->boolean('retryable');
            $table->boolean('outcome_known');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->integer('ksef_code')->nullable();
            $table->text('message');
            $table->json('details')->nullable();
            $table->string('reference_number', 128)->nullable();
            $table->unsignedBigInteger('submission_id')->nullable()->index();
            $table->unsignedBigInteger('sync_run_id')->nullable()->index();
            $table->timestamp('occurred_at')->index();
        });

        Schema::create('pl_ksef_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained('pl_ksef_credentials')->nullOnDelete();
            $table->string('environment', 20);
            $table->unsignedBigInteger('erp_invoice_id')->nullable()->index();
            $table->unsignedBigInteger('erp_sales_order_id')->nullable()->index();
            $table->string('invoice_number', 256);
            $table->string('invoice_type', 8)->default('VAT');
            $table->string('seller_nip', 10);
            $table->string('buyer_label')->nullable();
            $table->date('issue_date')->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->decimal('net', 14, 2)->nullable();
            $table->decimal('vat', 14, 2)->nullable();
            $table->decimal('gross', 14, 2)->nullable();
            // UNIQUE while live, NULL when rejected/cancelled. The idempotency guard.
            $table->char('active_key', 64)->nullable()->unique();
            $table->foreignId('document_id')->nullable()->constrained('pl_ksef_invoice_documents')->nullOnDelete();
            $table->foreignId('upo_document_id')->nullable()->constrained('pl_ksef_invoice_documents')->nullOnDelete();
            $table->string('state', 16);
            $table->integer('ksef_status_code')->nullable();
            $table->text('ksef_status_description')->nullable();
            $table->json('ksef_status_details')->nullable();
            $table->string('session_reference', 64)->nullable()->index();
            $table->string('invoice_reference', 64)->nullable()->unique();
            $table->string('ksef_number', 40)->nullable()->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('acquisition_date')->nullable();
            $table->timestamp('permanent_storage_date')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('manual_review_reason')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->foreignId('last_error_id')->nullable()->constrained('pl_ksef_errors')->nullOnDelete();
            $table->timestamp('next_retry_at')->nullable();
            $table->string('prepared_by')->nullable();
            $table->string('sent_by')->nullable();
            $table->timestamps();

            $table->index(['tax_profile_id', 'state']);
        });

        Schema::create('pl_ksef_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->nullable()->constrained('pl_ksef_submissions')->cascadeOnDelete();
            $table->foreignId('ksef_document_id')->nullable()->constrained('pl_ksef_documents')->cascadeOnDelete();
            $table->string('from_state', 16)->nullable();
            $table->string('to_state', 16);
            $table->integer('ksef_code')->nullable();
            $table->text('ksef_description')->nullable();
            $table->json('details')->nullable();
            $table->string('source', 16);                     // api | user | system | validator
            $table->string('reference', 128)->nullable();
            $table->string('actor')->nullable();
            $table->timestamp('occurred_at')->index();
        });

        Schema::create('pl_ksef_sync_cursors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('subject_type', 20);
            $table->timestamp('synced_through')->nullable();
            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_to')->nullable();
            $table->unsignedInteger('page_offset')->default(0);
            $table->boolean('in_progress')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('last_run_completed')->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'environment', 'subject_type'], 'pl_ksef_sync_cursors_scope_unique');
        });

        Schema::create('pl_ksef_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('subject_type', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 16)->default('RUNNING');   // RUNNING | COMPLETED | PARTIAL | FAILED
            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_to')->nullable();
            $table->unsignedInteger('pages_persisted')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->json('report')->nullable();
            $table->text('stopped_because')->nullable();
            $table->foreignId('error_id')->nullable()->constrained('pl_ksef_errors')->nullOnDelete();
            $table->string('triggered_by')->nullable();
            $table->timestamps();

            $table->index(['tax_profile_id', 'started_at']);
        });

        Schema::create('pl_ksef_customer_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_customer_id');
            $table->string('identifier_type', 8);              // nip | vat_ue | other | none
            $table->string('identifier_value', 50)->nullable();
            $table->string('identifier_country', 2)->nullable();
            $table->string('address_country', 2)->nullable();
            $table->string('address_line1', 512)->nullable();
            $table->string('address_line2', 512)->nullable();
            $table->boolean('local_government_sub_unit')->default(false);
            $table->boolean('vat_group_member')->default(false);
            $table->string('confirmed_by')->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'erp_customer_id'], 'pl_ksef_customer_identifiers_unique');
        });

    }

    public function down(): void
    {
        Schema::table('pl_ksef_documents', function (Blueprint $table): void {
            $table->dropColumn(['subject_type', 'sync_run_id', 'invoice_hash_base64', 'acquisition_date', 'invoicing_date', 'form_system_code', 'form_schema_version', 'xml_missing_reason']);
        });
        Schema::dropIfExists('pl_ksef_customer_identifiers');
        Schema::dropIfExists('pl_ksef_sync_runs');
        Schema::dropIfExists('pl_ksef_sync_cursors');
        Schema::dropIfExists('pl_ksef_status_events');
        Schema::dropIfExists('pl_ksef_submissions');
        Schema::dropIfExists('pl_ksef_errors');
        Schema::dropIfExists('pl_ksef_invoice_documents');
        Schema::dropIfExists('pl_ksef_auth_sessions');
        Schema::table('pl_ksef_credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'token_fingerprint', 'token_reference', 'token_permissions', 'observed_permissions',
                'seller_address_country', 'seller_address_line1', 'seller_address_line2', 'seller_email', 'seller_phone',
                'issue_place', 'exemption_legal_basis', 'default_payment_form', 'bank_account', 'bank_name',
                'system_info', 'api_version', 'setup_step', 'last_connection_test_at', 'last_connection_ok',
                'last_connection_detail', 'last_auth_at', 'last_sync_at', 'last_sync_ok', 'production_enabled_at', 'production_enabled_by',
            ]);
        });
    }
};
