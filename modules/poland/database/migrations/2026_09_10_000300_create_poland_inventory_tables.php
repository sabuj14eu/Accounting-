<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage B, section 5: optional inventory.
 *
 * Quantities are integer thousandths of the product's stock unit, never
 * floats. The unique (source_type, source_id, source_line_no) index is the
 * wall that makes one invoice line produce one movement, ever — a retried
 * approval lands on it and stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pl_products')->cascadeOnDelete();
            $table->bigInteger('quantity_thousandths');            // signed
            $table->string('unit', 8);
            $table->string('movement_type', 20);                   // purchase | count_adjustment | manual_adjustment | reversal
            $table->string('source_type', 40);                     // purchase_invoice_line | count | manual
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('source_line_no')->default(0);
            $table->foreignId('reverses_movement_id')->nullable()->constrained('pl_inventory_movements')->nullOnDelete();
            $table->date('occurred_on');
            $table->string('recorded_by');
            $table->timestamp('recorded_at');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'source_line_no'], 'pl_inventory_movements_source');
            $table->index(['product_id', 'occurred_on']);
        });

        Schema::create('pl_inventory_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained('pl_tax_profiles')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pl_products')->cascadeOnDelete();
            $table->date('counted_on');
            $table->bigInteger('quantity_thousandths');
            $table->string('unit', 8);

            // An opening count starts the book; a later count is compared to it.
            $table->boolean('is_opening')->default(false);
            // What the book said at the moment of counting, so the arithmetic
            // can be replayed. NULL when there was no opening count to book from.
            $table->bigInteger('book_quantity_thousandths')->nullable();

            $table->string('counted_by');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'counted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_inventory_counts');
        Schema::dropIfExists('pl_inventory_movements');
    }
};
