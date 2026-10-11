<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Derived, rebuildable read model of "تقرير كشف الحساب".
 *
 * The ledger (transactions / transaction_lines) stays the only source of
 * truth: every row here is re-derived from it by
 * App\Services\Reports\AccountStatement\AccountStatementProjector inside the
 * same DB transaction as the business write that changed it, and the whole
 * set can be rebuilt at any time with
 * `php artisan oms:rebuild-account-statement-projection`.
 *
 * No foreign keys on purpose: the projection must never block or cascade
 * into a ledger write; the independent verifier is what proves it matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per ACTIVE line of an ACTIVE transaction. Stable keys and
        // amounts only — account/type/project labels, descriptions and notes
        // are read from the ledger for the 50 rows on screen.
        //
        // debit/credit are the statement's own amounts: amount_currency on
        // the side given by debit_base > 0 (exactly the original report).
        //
        // The primary key is (account, canonical order), so one account's
        // history is one contiguous clustered range: a page of one account is
        // a short index walk, and an intra-day running-balance read touches
        // only that account's lines of that day.
        Schema::create('account_statement_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id');
            $table->dateTime('transaction_time');
            $table->unsignedBigInteger('line_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('transaction_type_id');
            $table->unsignedBigInteger('project_cost_id')->nullable();
            $table->unsignedBigInteger('currency_id');
            $table->decimal('debit', 15, 2);
            $table->decimal('credit', 15, 2);

            $table->primary(['account_id', 'transaction_time', 'line_id'], 'asl_primary');
            $table->unique('line_id', 'asl_line_unique');
            // The projector replaces a transaction's rows by transaction id.
            $table->index('transaction_id', 'asl_transaction_index');
            // Global canonical order (+ the implicit account_id of the primary
            // key, + the type): a many-account page walks this index in order
            // and evaluates the account/type filters inside it.
            $table->index(['transaction_time', 'line_id', 'transaction_type_id'], 'asl_global_order_index');
            // Project filter: a project's lines are few, so the matching rows
            // are read through this index and ordered in memory.
            $table->index(['project_cost_id', 'transaction_time', 'line_id'], 'asl_project_cost_index');
        });

        // Exact per-account per-day totals (all types). Balance checkpoints:
        // with the monthly totals below, the balance before any instant is a
        // bounded sum (whole months + days of that month + that day's lines).
        Schema::create('account_statement_daily_totals', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id');
            $table->date('day');
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('line_count');
            $table->decimal('debit_total', 24, 2);
            $table->decimal('credit_total', 24, 2);

            $table->primary(['account_id', 'day', 'currency_id'], 'asdt_primary');
        });

        // Exact per-account per-month totals by transaction type: whole-month
        // balance prefixes (summed over types) and type-filtered totals.
        Schema::create('account_statement_monthly_totals', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id');
            $table->date('month');
            $table->unsignedBigInteger('transaction_type_id');
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('line_count');
            $table->decimal('debit_total', 24, 2);
            $table->decimal('credit_total', 24, 2);

            $table->primary(['account_id', 'month', 'transaction_type_id', 'currency_id'], 'asmt_primary');
            // Type-filtered totals over many accounts: read only the chosen
            // types' rows (covering). Measured on oms_stress, ~4,000 accounts,
            // a rare type over the full history: 365 ms → see TASKS_LOG.
            $table->index(['transaction_type_id', 'month', 'account_id', 'currency_id', 'line_count', 'debit_total', 'credit_total'], 'asmt_type_month_index');
        });

        // Exact all-time totals per account (and line currency). A balance
        // before an instant is computed from whichever side has fewer
        // checkpoint rows: prefix sums, or all-time minus the suffix — so a
        // statement ending today (or starting before the first line) costs
        // O(accounts), not O(accounts × months).
        Schema::create('account_statement_account_totals', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('line_count');
            $table->decimal('debit_total', 24, 2);
            $table->decimal('credit_total', 24, 2);

            $table->primary(['account_id', 'currency_id'], 'asat_primary');
        });

        // Single row (id = 1). Only 'ready' lets the report read the
        // projection.
        Schema::create('account_statement_projection_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('status', 20);
            $table->string('phase', 20)->nullable();
            $table->unsignedBigInteger('resume_after')->nullable();
            $table->timestamp('rebuild_started_at')->nullable();
            $table->timestamp('rebuilt_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // An empty ledger has an exactly empty projection: ready at once
        // (fresh installs, the test database). Any existing ledger needs a
        // rebuild before the report may read the projection.
        $ledgerIsEmpty = ! DB::table('transactions')->exists();

        DB::table('account_statement_projection_state')->insert([
            'id' => 1,
            'status' => $ledgerIsEmpty ? 'ready' : 'missing',
            'rebuilt_at' => $ledgerIsEmpty ? now() : null,
            'note' => $ledgerIsEmpty ? null : 'Run php artisan oms:rebuild-account-statement-projection --verify',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_statement_projection_state');
        Schema::dropIfExists('account_statement_account_totals');
        Schema::dropIfExists('account_statement_monthly_totals');
        Schema::dropIfExists('account_statement_daily_totals');
        Schema::dropIfExists('account_statement_lines');
    }
};
