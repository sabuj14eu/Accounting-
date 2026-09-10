<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage B, section 6: the monthly sales report gains a CHANNEL on each line
 * (shop register, Glovo, other). Sales stay monthly; this only splits the
 * month. Nothing here is keyed by day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pl_sales_report_lines', function (Blueprint $table): void {
            $table->string('channel', 20)->default('shop_register')->after('designation')->index();
            // A Glovo line points at the settlement it was written from.
            $table->string('source_type', 40)->nullable()->after('note');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
        });
    }

    public function down(): void
    {
        Schema::table('pl_sales_report_lines', function (Blueprint $table): void {
            $table->dropColumn(['channel', 'source_type', 'source_id']);
        });
    }
};
