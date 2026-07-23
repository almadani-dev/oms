<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Task 7C.1 — the two additive columns the approved restore
 * architecture needs on top of the Task 7B.1 schema (see docs/DECISIONS_LOG.md
 * for the full delta-plan reasoning that replaced an earlier two-narrow-
 * column proposal with this single bounded JSON column plus a concurrency
 * nonce):
 *
 * - restore_metadata: the single restore-specific audit container (requester
 *   identity snapshot, source/safety backup UUID snapshots, confirmation
 *   timestamp, bounded phase history, sanitized reconciliation/result). Never
 *   holds a confirmation phrase, password, key, raw command line, or
 *   unbounded/unsanitized exception text. Not written by any code in this
 *   phase — see BackupOperation's docblock for the intended shape and
 *   BackupOperationRestoreMetadataTest for the schema this phase locks in.
 * - launch_nonce: a concurrency/idempotency key (not audit data) the future
 *   signed launch endpoint (Task 7C.4) matches on in an atomic conditional
 *   UPDATE, so a replayed signed launch URL can never claim/spawn a second
 *   restore process for the same request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_operations', function (Blueprint $table) {
            $table->json('restore_metadata')->nullable()->after('error_summary');
            $table->string('launch_nonce', 64)->nullable()->after('operation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('backup_operations', function (Blueprint $table) {
            $table->dropColumn(['restore_metadata', 'launch_nonce']);
        });
    }
};
