<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage B, sections 4.1–4.3: invoice lines, master data and postings.
 *
 * Two of these tables carry an idempotency wall in the database rather than in
 * application flow, because the flow is exactly what fails during a retry:
 *  - pl_purchase_postings.ksef_document_id is UNIQUE — one posting per document;
 *  - pl_product_aliases has one row per (supplier, spelling, index), so a
 *    mapping made once is found every time afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('nip', 20);
            $table->string('name')->nullable();
            $table->timestamp('first_seen_at');
            $table->unsignedInteger('invoice_count')->default(0);
            $table->string('last_cost_category', 40)->nullable();
            $table->timestamps();

            $table->unique(['tax_profile_id', 'nip']);
        });

        Schema::create('pl_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('category', 40)->nullable();
            $table->string('stock_unit', 8);                       // szt | kg | l | op

            // FALSE BY DEFAULT. Turning it on is a logged owner action and takes
            // effect for invoices approved after the change.
            $table->boolean('inventory_tracked')->default(false);
            $table->timestamp('tracking_changed_at')->nullable();

            $table->string('default_cost_category', 40);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tax_profile_id', 'code']);
        });

        Schema::create('pl_product_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pl_products')->cascadeOnDelete();
            $table->string('supplier_nip', 20);
            $table->string('normalised_description');
            $table->string('supplier_index', 64)->default('');   // '' rather than NULL so the unique index holds
            $table->decimal('pack_size', 12, 3)->nullable();     // stock units per ONE invoice unit
            $table->string('pack_unit', 20)->nullable();         // the invoice unit the pack size refers to
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['tax_profile_id', 'supplier_nip', 'normalised_description', 'supplier_index'],
                'pl_product_aliases_identity',
            );
        });

        Schema::create('pl_purchase_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ksef_document_id')->constrained('pl_ksef_documents')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->text('description')->nullable();
            $table->string('supplier_index', 64)->nullable();
            $table->string('gtin', 32)->nullable();
            $table->string('pkwiu', 32)->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_net_price', 14, 4)->nullable();
            $table->decimal('net', 14, 2)->nullable();
            $table->string('vat_rate', 8)->nullable();
            $table->decimal('vat', 14, 2)->nullable();
            $table->boolean('vat_is_derived')->default(false);
            $table->decimal('gross', 14, 2)->nullable();
            $table->boolean('gross_is_derived')->default(false);
            $table->string('gtu', 8)->nullable();
            $table->string('procedure', 16)->nullable();

            // Resolved mapping. NULL = accounting only, no product, no stock.
            $table->foreignId('product_id')->nullable()->constrained('pl_products')->nullOnDelete();
            $table->string('mapping_source', 10)->default('none');   // alias | owner | none
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['ksef_document_id', 'line_no']);
        });

        Schema::create('pl_purchase_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();

            // THE idempotency wall. Approving twice, from two tabs or a retried
            // job, cannot create a second cost.
            $table->foreignId('ksef_document_id')->unique()->constrained('pl_ksef_documents')->cascadeOnDelete();

            $table->string('booking_period', 7)->index();
            $table->string('vat_period', 7)->index();
            $table->string('receipt_period', 7);
            $table->decimal('deductible_net', 14, 2);
            $table->decimal('deductible_input_vat', 14, 2);
            $table->decimal('non_deductible_net', 14, 2)->default(0);
            $table->decimal('non_deductible_vat', 14, 2)->default(0);
            $table->decimal('deductible_share', 5, 4)->default(1);
            $table->json('by_rate');
            $table->string('cost_category', 40);
            $table->unsignedTinyInteger('kpir_column');

            // posted | reversed. A posting is never updated except to record
            // what reversed it.
            $table->string('status', 12)->default('posted')->index();
            $table->foreignId('adjusts_posting_id')->nullable()->constrained('pl_purchase_postings')->nullOnDelete();
            $table->foreignId('reversed_by_posting_id')->nullable()->constrained('pl_purchase_postings')->nullOnDelete();

            $table->json('plan');                                   // the PostingPlan as shown to the owner
            $table->string('posted_by');
            $table->timestamp('posted_at');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_purchase_postings');
        Schema::dropIfExists('pl_purchase_invoice_lines');
        Schema::dropIfExists('pl_product_aliases');
        Schema::dropIfExists('pl_products');
        Schema::dropIfExists('pl_suppliers');
    }
};
