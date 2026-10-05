<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived, rebuildable read model of "تقرير الحركات المالية الشامل".
 *
 * The ledger (transactions / transaction_lines) stays the only source of
 * truth: every row here is recomputed from it by
 * App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjector,
 * inside the same DB transaction as the business write that changed it, and
 * the whole set can be dropped and rebuilt with
 * `php artisan oms:rebuild-comprehensive-report-projection` at any time.
 *
 * No foreign keys on purpose: the projection must never be able to block or
 * cascade into a ledger write, and the drift verifier — not a constraint —
 * is what proves it matches the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per ACTIVE line of an ACTIVE transaction: IDs, filter keys,
        // order keys and amounts only. Labels, descriptions and notes are
        // never copied — they are read from the ledger for the rendered page.
        //
        // The primary key IS the report's canonical order, so InnoDB stores
        // the rows clustered in that order: a period is one contiguous range
        // and every keyset page is a short index walk. Each secondary index
        // implicitly ends with the primary key, i.e. it is ordered by
        // (filter key, transaction_time, transaction_id, line_id).
        Schema::create('comprehensive_report_lines', function (Blueprint $table) {
            $table->dateTime('transaction_time');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('line_id');
            $table->unsignedBigInteger('transaction_type_id');
            $table->unsignedBigInteger('account_id');
            // Copy of accounts.account_type_id, kept current by the Account
            // observer (an account's type is editable).
            $table->unsignedBigInteger('account_type_id');
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('project_cost_id')->nullable();
            $table->decimal('debit', 15, 2);
            $table->decimal('credit', 15, 2);

            $table->primary(['transaction_time', 'transaction_id', 'line_id'], 'crl_canonical_primary');
            $table->unique('line_id', 'crl_line_unique');
            $table->index('transaction_id', 'crl_transaction_index');
            $table->index('transaction_type_id', 'crl_type_index');
            $table->index('account_type_id', 'crl_account_type_index');
            $table->index('currency_id', 'crl_currency_index');
            $table->index('project_cost_id', 'crl_project_cost_index');

            // Measured on oms_stress (1.22M lines), not speculative:
            //  - (type, currency) + the implicit PK: the exact first line of a
            //    type × currency group is one index seek (summary ordering);
            //  - the account index is in keyset order (account, time,
            //    transaction, line) AND covers every column an account
            //    summary reads, so account pages walk it without sorting and
            //    account summaries never touch the rows (accounts 1,4,8 debit
            //    side, full range: 5.5 s -> ~1 s).
            $table->index(['transaction_type_id', 'currency_id'], 'crl_type_currency_index');
            $table->index(['account_id', 'transaction_time', 'transaction_id', 'line_id', 'transaction_type_id', 'currency_id', 'debit', 'credit'], 'crl_account_index');
        });

        // Exact per-month line totals of the projection, by every dimension
        // the aggregate summaries can filter on. Lines of soft-deleted
        // accounts are INCLUDED here; the report's exclusion of them is
        // applied at query time, so that rule can change without a rebuild.
        Schema::create('comprehensive_report_monthly_totals', function (Blueprint $table) {
            $table->date('month');
            $table->unsignedBigInteger('transaction_type_id');
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('account_type_id');
            $table->unsignedBigInteger('line_count');
            $table->decimal('debit_total', 24, 2);
            $table->decimal('credit_total', 24, 2);

            $table->primary(['month', 'transaction_type_id', 'currency_id', 'account_type_id'], 'crmt_primary');
        });

        // Distinct-transaction counts cannot be summed across currencies or
        // account types (one transaction may touch several), so each
        // transaction is counted once, under the SIGNATURE of the
        // (currency, account type) pairs its lines carry. Any currency set ×
        // account type filter is then an exact sum over matching signatures.
        Schema::create('comprehensive_report_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature', 512)->unique('crs_signature_unique');
        });

        Schema::create('comprehensive_report_monthly_transactions', function (Blueprint $table) {
            $table->date('month');
            $table->unsignedBigInteger('transaction_type_id');
            $table->unsignedBigInteger('signature_id');
            $table->unsignedBigInteger('transaction_count');

            $table->primary(['month', 'transaction_type_id', 'signature_id'], 'crmtx_primary');
        });

        // Single row (id = 1). Only 'ready' lets the report read the
        // projection; anything else falls back to the ledger snapshot path,
        // so a missing, rebuilding or drifted projection can never show
        // wrong figures.
        Schema::create('comprehensive_report_projection_state', function (Blueprint $table) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('comprehensive_report_projection_state');
        Schema::dropIfExists('comprehensive_report_monthly_transactions');
        Schema::dropIfExists('comprehensive_report_signatures');
        Schema::dropIfExists('comprehensive_report_monthly_totals');
        Schema::dropIfExists('comprehensive_report_lines');
    }
};
