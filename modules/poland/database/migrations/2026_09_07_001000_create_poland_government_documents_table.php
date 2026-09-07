<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The government inbox: letters received, what was read, what must be done. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_government_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();

            $table->string('filename');
            $table->string('stored_path')->nullable();
            $table->string('checksum', 64);
            $table->timestamp('received_at');
            $table->string('uploaded_by')->nullable();

            $table->string('authority', 30)->default('unknown');
            $table->string('action', 30)->default('unknown')->index();
            $table->string('document_type')->nullable();
            $table->date('issue_date')->nullable();
            $table->string('case_number')->nullable();
            $table->string('taxpayer')->nullable();
            $table->string('tax_period', 7)->nullable()->index();

            // What the LETTER says. Never a tax liability — the engine owns that,
            // and a difference between the two is escalated, not resolved.
            $table->decimal('stated_amount', 14, 2)->nullable();
            $table->date('payment_deadline')->nullable();
            $table->date('response_deadline')->nullable();
            $table->text('required_action')->nullable();

            $table->boolean('text_unavailable')->default(false);
            $table->boolean('needs_manual_review')->default(true);
            $table->text('extraction_note')->nullable();
            $table->json('extracted')->nullable();
            $table->longText('extracted_text')->nullable();

            $table->string('status', 20)->default('new');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->unique(['tax_profile_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_government_documents');
    }
};
