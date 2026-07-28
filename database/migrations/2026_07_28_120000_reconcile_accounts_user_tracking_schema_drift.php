<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Task 8.3 — schema drift reconciliation for `accounts.created_by` /
 * `accounts.updated_by`.
 *
 * Both columns exist live with no migration file anywhere in this repo's
 * history — flagged as an out-of-scope discovery during Task 8.2's
 * fresh-vs-local comparison (see docs/DECISIONS_LOG.md, 2026-07-28, OMS
 * Task 8.2). Read-only audit found zero application code usage on `Account`
 * (not `$fillable`, no relationship, no form field — the `created_by`/
 * `updated_by` writes inside `CreateAccount.php` are for the opening-balance
 * `Transaction`/`TransactionLine` side-effect rows, never for the `Account`
 * row itself) and zero non-null values, both currently (0 of 5 accounts) and
 * in the earliest captured historical backup, 2026-07-06 (0 of 16 accounts)
 * — an unbroken, always-NULL history.
 *
 * Despite zero data, this is classified ACTIVE_REQUIRED rather than
 * DEAD_UNUSED: the column shape (nullable, FK to `users`, ON DELETE SET
 * NULL) is column-for-column identical to the active `HasUserTracking`
 * audit-metadata convention used by 20+ other current models, and to
 * `accounts_type.created_by`/`updated_by` — reconciled the same way, as
 * ACTIVE_REQUIRED, one task ago (2026_07_28_110001). This migration
 * reconciles SCHEMA ONLY. `Account` is deliberately NOT wired to
 * `App\Traits\HasUserTracking` here — activating creator/updater tracking
 * behavior is explicitly deferred to the future Task 9 Audit Log work, per
 * this task's own restrictions ("do not start Audit Log").
 *
 * See docs/DECISIONS_LOG.md (2026-07-28, OMS Task 8.3) for the full evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureUserTrackingColumn('created_by', afterColumn: 'notes');
        $this->ensureUserTrackingColumn('updated_by', afterColumn: 'created_by');
    }

    /**
     * Adds the column (+ its FK) only if missing; if the column already
     * exists (real live drift) but its FK is somehow missing, adds only the
     * FK. Never touches an already-complete column/FK pair, and never
     * duplicates a constraint/index name.
     */
    private function ensureUserTrackingColumn(string $column, string $afterColumn): void
    {
        $foreignKeyName = "accounts_{$column}_foreign";

        if (! Schema::hasColumn('accounts', $column)) {
            Schema::table('accounts', function (Blueprint $table) use ($column, $afterColumn) {
                $table->foreignId($column)->nullable()->after($afterColumn)->constrained('users')->nullOnDelete();
            });

            return;
        }

        $hasForeignKey = collect(Schema::getForeignKeys('accounts'))->contains('name', $foreignKeyName);

        if (! $hasForeignKey) {
            Schema::table('accounts', function (Blueprint $table) use ($column) {
                $table->foreign($column)->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Intentionally irreversible (no-op) — same principle established for
     * `accounts_type` in Task 8.2 (2026_07_28_110001). On the real local
     * database, `created_by`/`updated_by` already existed as live drift
     * before this migration ever ran, so `up()` cannot tell "I added these"
     * apart from "these already existed"; a rollback that dropped them would
     * destroy real pre-existing schema (and, once Task 9 Audit Log starts
     * populating them, real data) this migration never created. No marker
     * table or migration-state tracking is used to work around that.
     */
    public function down(): void
    {
        // No-op by design — see docblock above.
    }
};
