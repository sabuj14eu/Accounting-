<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-format duplicate detection.
 *
 * The exact fingerprint catches re-importing the same file. It does NOT catch
 * the same transaction arriving in two different formats: MT940 supplies a
 * counterparty account that camt.053 omits, so the fingerprints differ and the
 * month's costs double.
 *
 * Making the fingerprint looser would be worse — two genuine same-day payments
 * of the same amount would silently collapse into one. So the second row is
 * imported and FLAGGED, and a person decides. Neither silently doubling nor
 * silently dropping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pl_bank_transactions', function (Blueprint $table): void {
            $table->foreignId('possible_duplicate_of')->nullable()->after('fingerprint')
                ->constrained('pl_bank_transactions')->nullOnDelete();
            $table->text('duplicate_reason')->nullable()->after('possible_duplicate_of');
            $table->string('duplicate_decision', 20)->nullable()->after('duplicate_reason');
        });
    }

    public function down(): void
    {
        Schema::table('pl_bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('possible_duplicate_of');
            $table->dropColumn(['duplicate_reason', 'duplicate_decision']);
        });
    }
};
