<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for every accounting or compliance action.
 *
 * No update and no delete path exists for this table anywhere in the module.
 * An audit log that can be edited is a log that proves nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tax_profile_id')->nullable()->index();
            $table->string('action', 60)->index();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('period', 7)->nullable()->index();

            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('actor_ip', 45)->nullable();

            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('result', 20)->default('ok');
            $table->text('error')->nullable();
            $table->string('source', 40)->default('web');

            $table->timestamp('occurred_at')->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_audit_events');
    }
};
