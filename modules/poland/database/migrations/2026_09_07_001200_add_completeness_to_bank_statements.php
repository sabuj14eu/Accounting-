<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statement completeness.
 *
 * The system must know whether the bank data is complete for a month. Without
 * this, "3 transactions imported" silently reads as "all of August", and a
 * statement covering 1-15 August understates costs exactly as much as importing
 * nothing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pl_bank_statements', function (Blueprint $table): void {
            // COMPLETE | PARTIAL | UNKNOWN | OUTSIDE_PERIOD
            $table->string('completeness', 20)->default('UNKNOWN')->after('balances_reconcile');
            $table->string('covered_range')->nullable()->after('completeness');
            $table->boolean('has_balances')->default(false)->after('covered_range');
        });
    }

    public function down(): void
    {
        Schema::table('pl_bank_statements', function (Blueprint $table): void {
            $table->dropColumn(['completeness', 'covered_range', 'has_balances']);
        });
    }
};
