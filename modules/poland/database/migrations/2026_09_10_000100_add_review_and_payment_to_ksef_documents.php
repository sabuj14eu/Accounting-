<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage B of docs/ACCOUNTING_WORKFLOW_AND_DATA_MODEL.md, section 4.1.
 *
 * Additive only. The document of record (original_xml, ksef_number) stays
 * immutable; these columns carry what the review flow needs: payment terms
 * read from the FA, the approval state and who set it, the link from a
 * correction to its original, the second duplicate wall (invoice fingerprint)
 * and where the document came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pl_ksef_documents', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('sale_date');
            $table->date('service_period_from')->nullable()->after('due_date');
            $table->date('service_period_to')->nullable()->after('service_period_from');

            // FA `Platnosc`. paid_on_invoice is NULL when the element is absent:
            // absence is not "unpaid".
            $table->string('payment_form', 20)->nullable()->after('ksef_status');
            $table->boolean('paid_on_invoice')->nullable()->after('payment_form');
            $table->string('supplier_account', 40)->nullable()->after('paid_on_invoice');
            $table->json('payment_terms_json')->nullable()->after('supplier_account');

            // ksef now; `manual` is a later, optional path. Never inferred.
            $table->string('source', 20)->default('ksef')->after('payment_terms_json');

            // imported | awaiting_review | approved | posted | rejected | superseded
            $table->string('approval_status', 20)->default('imported')->after('needs_review')->index();
            $table->string('approved_by')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('decision_note')->nullable()->after('approved_at');

            // A correction invoice → the document it corrects. The original is
            // never edited; the correction posts as a signed adjustment.
            $table->foreignId('corrects_document_id')->nullable()->after('decision_note')
                ->constrained('pl_ksef_documents')->nullOnDelete();

            // Second duplicate wall: same seller, number, date and gross under
            // a different KSeF number. Flagged, excluded, decided by a person.
            $table->string('invoice_fingerprint', 64)->nullable()->after('corrects_document_id')->index();
            $table->foreignId('possible_duplicate_of')->nullable()->after('invoice_fingerprint')
                ->constrained('pl_ksef_documents')->nullOnDelete();
            $table->text('duplicate_reason')->nullable()->after('possible_duplicate_of');
            $table->string('duplicate_decision', 20)->nullable()->after('duplicate_reason');
        });
    }

    public function down(): void
    {
        Schema::table('pl_ksef_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('possible_duplicate_of');
            $table->dropConstrainedForeignId('corrects_document_id');
            $table->dropColumn([
                'due_date', 'service_period_from', 'service_period_to',
                'payment_form', 'paid_on_invoice', 'supplier_account', 'payment_terms_json',
                'source', 'approval_status', 'approved_by', 'approved_at', 'decision_note',
                'invoice_fingerprint', 'duplicate_reason', 'duplicate_decision',
            ]);
        });
    }
};
