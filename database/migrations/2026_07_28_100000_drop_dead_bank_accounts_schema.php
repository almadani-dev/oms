<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Task 8.1 — schema drift reconciliation. `bank_accounts`,
 * `transactions.bank_account_id`, and `accounts.parent_id` existed live with
 * real FK constraints but were never defined by any migration file (see
 * docs/DECISIONS_LOG.md 2026-07-27 "schema/migration-history drift" entry —
 * documented-only at the time, decision deferred to this task). Audit found
 * zero application code usage (no model, no relationship, no fillable, no
 * Filament resource/form, no factory/seeder) and zero non-null/live data,
 * both currently and in the earliest captured historical backup
 * (2026-07-06) — the single ever-existing `bank_accounts` row predates that
 * backup and was already purged by the user-approved 2026-07-06 operational
 * data reset. Classified DEAD_UNUSED; see docs/AI_PROJECT_MEMORY.md
 * (OMS Task 8.1) for the full evidence trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'bank_account_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropForeign(['bank_account_id']);
                $table->dropColumn('bank_account_id');
            });
        }

        if (Schema::hasColumn('accounts', 'parent_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            });
        }

        Schema::dropIfExists('bank_accounts');
    }

    /**
     * Intentionally irreversible (no-op). `bank_accounts`, `accounts.parent_id`,
     * and `transactions.bank_account_id` were never represented anywhere in this
     * repository's migration history to begin with — `up()` reconciles a live-DB
     * accident, not a schema this repository ever defined. Recreating them here
     * would reintroduce dead schema on any fresh install that never had them
     * (`migrate:fresh` then `migrate:rollback` must not resurrect DEAD_UNUSED
     * schema — see docs/DECISIONS_LOG.md, 2026-07-28, OMS Task 8.1).
     */
    public function down(): void
    {
        // No-op by design — see docblock above.
    }
};
