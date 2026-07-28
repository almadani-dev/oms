<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Task 8.2 — schema drift reconciliation. `accounts.account_code` is
 * `NOT NULL` live, but the original `create_accounts_table` migration
 * (unchanged since the initial commit) has always declared it
 * `->nullable()->unique()`, `AccountForm` explicitly sets `->nullable()`,
 * and every read site across the codebase (reports/exports/dropdowns)
 * defensively guards `$account->account_code ? ... : ''` — the whole
 * application is written assuming it can be empty. The live `NOT NULL` is
 * therefore the drift, not the migration file. Zero null/empty values exist
 * in the real local data today, so relaxing it loses nothing. See
 * docs/DECISIONS_LOG.md (2026-07-28, OMS Task 8.2) for the full evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isNullable('accounts', 'account_code')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->string('account_code')->nullable()->change();
            });
        }
    }

    /**
     * Intentionally irreversible (no-op). A fresh install's `account_code`
     * has been nullable since the very first migration — this migration only
     * corrects a live-DB-only accident. Forcing it back to `NOT NULL` here
     * would impose a constraint no fresh install has ever actually had.
     */
    public function down(): void
    {
        // No-op by design — see docblock above.
    }

    private function isNullable(string $table, string $column): bool
    {
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        return $definition !== null && (bool) $definition['nullable'];
    }
};
