<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage B, section 4.3 coexistence rule: the manual monthly purchase total can
 * be marked as superseded by posted invoices. The row stays; the timestamp
 * says it no longer feeds the engine. Never summed with postings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pl_purchase_summaries', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('pl_purchase_summaries', function (Blueprint $table): void {
            $table->dropColumn('superseded_at');
        });
    }
};
