<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Task 8.2 — schema drift reconciliation for `accounts_type`.
 *
 * `nature` (asset/liability/equity/revenue/expense) exists live with no
 * migration file and zero code usage anywhere — docs/AI_PROJECT_MEMORY.md
 * (2026-07-06, predating this audit) already documents the current design
 * as having "no account nature": every account is uniformly debit-normal.
 * Live values are mostly a MySQL implicit-ENUM-backfill artifact from when
 * the column was added (7 of 10 rows: 'asset' + created_by NULL), not
 * deliberate business data. Classified DEAD_UNUSED — dropped.
 *
 * `created_by`/`updated_by` also exist live with no migration file, but
 * match the exact FK-to-users/nullable/ON DELETE SET NULL audit-metadata
 * convention actively used (via `App\Traits\HasUserTracking`) by 20 other
 * current models, and carry real historical data (3 rows). Classified
 * ACTIVE_REQUIRED — reproduced here as schema only. This does NOT wire
 * `AccountType` to `HasUserTracking` — that is application behavior, out of
 * this task's scope (starting the Audit Log task), not a schema question.
 *
 * `down()` is entirely irreversible by design (see its own docblock below):
 * on the real local database, `created_by`/`updated_by` already existed as
 * live drift *before* this migration ever ran, so `up()` cannot tell "I
 * added these columns" apart from "these already existed" — a rollback that
 * blindly dropped them would destroy real pre-existing schema/data this
 * migration never created. No marker table or migration-state tracking is
 * used to work around that; the migration simply never reverses itself.
 *
 * See docs/DECISIONS_LOG.md (2026-07-28, OMS Task 8.2) for the full evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('accounts_type', 'nature')) {
            Schema::table('accounts_type', function (Blueprint $table) {
                $table->dropColumn('nature');
            });
        }

        if (! Schema::hasColumn('accounts_type', 'created_by')) {
            Schema::table('accounts_type', function (Blueprint $table) {
                $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('accounts_type', 'updated_by')) {
            Schema::table('accounts_type', function (Blueprint $table) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Intentionally irreversible (no-op) for the whole migration, not just
     * `nature`. This migration reconciles two possible divergent starting
     * states — a fresh install (never had `nature`/`created_by`/`updated_by`)
     * and the real live database (already had `created_by`/`updated_by` as
     * drift, plus `nature`, before this migration ever ran) — and `up()` has
     * no reliable way to tell, after the fact, which case it ran against.
     * Dropping `created_by`/`updated_by` here would be safe on a fresh
     * install but would destroy real pre-existing live data on a rollback of
     * the actual local database; recreating `nature` would resurrect dead
     * schema no fresh install has ever had. The only choice that is safe in
     * both cases is to do nothing at all.
     */
    public function down(): void
    {
        // No-op by design — see docblock above.
    }
};
