<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('type', 20); // manual|daily|weekly|pre_restore
            $table->string('scope', 20); // database|files|full
            $table->string('status', 20)->default('queued');
            // queued|running|verifying|completed|failed|deleting|deleted|
            // restoring|restored|restore_failed

            // Deterministic key for scheduled (daily/weekly) types only —
            // e.g. "daily:2026-07-22:full" / "weekly:2026-W30:full" (Asia/
            // Gaza-derived, see BackupCreationOrchestrator::buildDeduplicationKey()).
            // Null for manual/pre_restore. The unique index is the FINAL
            // concurrency guard against two workers enqueueing the same
            // scheduled backup at once — the app-level check in enqueue()
            // is only an optimization, not the source of truth.
            $table->string('deduplication_key', 191)->nullable()->unique();

            $table->string('disk', 32);
            $table->string('stored_path')->nullable();
            $table->string('encrypted_filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->unsignedSmallInteger('manifest_version')->nullable();
            $table->string('encryption_key_id', 64)->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->unsignedBigInteger('original_size_bytes')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('operation_reason')->nullable();
            $table->text('error_summary')->nullable();

            $table->boolean('is_protected')->default(false);

            $table->foreignId('source_backup_id')->nullable()->constrained('backup_operations')->nullOnDelete();
            $table->foreignId('pre_restore_safety_backup_id')->nullable()->constrained('backup_operations')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('status');
            $table->index('completed_at');
            $table->index('created_by');
            $table->index('is_protected');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_operations');
    }
};
