<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Derived, rebuildable read model of "تقرير الجهات المانحة".
 *
 * The business tables (project_cost_receipts / project_cost_budgets /
 * project_cost_budgets_payments) and the ledger (transactions /
 * transaction_lines) stay the only source of truth: every row here is
 * re-derived from them by
 * App\Services\Reports\DonorFinancialReport\Projection\DonorReportProjector
 * inside the same DB transaction as the financial write that changed them,
 * and the whole set can be rebuilt at any time with
 * `php artisan oms:rebuild-donor-financial-report-projection`.
 *
 * Projects, cost items, accounts and currencies are NOT copied: they are
 * read live (a donor has at most a few hundred projects / cost items), so a
 * project's donor / status / type / approval date or a cost item's project /
 * currency / deletion needs no projection maintenance at all.
 *
 * No foreign keys on purpose: the projection must never block or cascade
 * into a business write; the independent verifier is what proves it matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per ACTIVE receipt, real disbursement (budget with a
        // transaction) and execution payment (of an active budget). Stable
        // keys and amounts only — numbers, descriptions, notes and labels are
        // read live for the 50 rows on screen.
        //
        // kind is also the tie rank of the report's order (date text, then
        // receipts before payments; a disbursement's date carries a time, so
        // it sorts after the date-only rows of its day): the primary key is
        // that canonical order inside one cost item, so a donor's movements
        // are a few contiguous clustered ranges.
        Schema::create('donor_report_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('project_cost_id');
            $table->dateTime('sort_at');
            $table->unsignedTinyInteger('kind');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            // Listed among «الحركات المالية»: always for receipts / payments;
            // a disbursement only while its transaction is active (it still
            // counts in the disbursement summaries otherwise — the report's
            // current semantics).
            $table->boolean('listed');
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('amount_after', 15, 2)->nullable();
            $table->unsignedBigInteger('final_currency_id')->nullable();
            $table->decimal('final_amount', 15, 2)->nullable();

            $table->primary(['project_cost_id', 'sort_at', 'kind', 'source_id'], 'drm_primary');
            // The projector replaces a source's row by (kind, id).
            $table->unique(['kind', 'source_id'], 'drm_source_unique');
        });

        // The active accounting lines of each projected movement's
        // transaction (a disbursement's admin / transfer deduction lines
        // flagged), clustered right behind their movement.
        Schema::create('donor_report_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('project_cost_id');
            $table->unsignedTinyInteger('kind');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('line_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('currency_id');
            $table->decimal('debit', 15, 2);
            $table->decimal('credit', 15, 2);
            $table->decimal('amount_currency', 15, 2);
            // 0 = none, 1 = administrative, 2 = transfer deduction line.
            $table->unsignedTinyInteger('deduction');

            $table->primary(['project_cost_id', 'kind', 'source_id', 'line_id'], 'drl_primary');
        });

        // Single row (id = 1). Only 'ready' lets the report read the
        // projection; otherwise the page uses the legacy live service.
        Schema::create('donor_report_projection_state', function (Blueprint $table) {
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

        // No receipt / disbursement / payment yet: an exactly empty
        // projection, ready at once (fresh installs, the test database).
        // Anything else needs a rebuild before the report may read it.
        $empty = ! DB::table('project_cost_receipts')->exists()
            && ! DB::table('project_cost_budgets')->exists()
            && ! DB::table('project_cost_budgets_payments')->exists();

        DB::table('donor_report_projection_state')->insert([
            'id' => 1,
            'status' => $empty ? 'ready' : 'missing',
            'rebuilt_at' => $empty ? now() : null,
            'note' => $empty ? null : 'Run php artisan oms:rebuild-donor-financial-report-projection --verify',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_report_projection_state');
        Schema::dropIfExists('donor_report_lines');
        Schema::dropIfExists('donor_report_movements');
    }
};
