<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_cost_budgets_payments', function (Blueprint $table) {
            // The disbursement no longer captures المبلغ المرصود; the project
            // linkage now lives on the transaction lines (project_cost_id).
            // Keep the column for historic rows but allow it to be null.
            $table->dropForeign(['project_cost_budget_id']);
            $table->foreignId('project_cost_budget_id')->nullable()->change();
            $table->foreign('project_cost_budget_id')
                ->references('id')->on('project_cost_budgets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_cost_budgets_payments', function (Blueprint $table) {
            $table->dropForeign(['project_cost_budget_id']);
            $table->foreignId('project_cost_budget_id')->nullable(false)->change();
            $table->foreign('project_cost_budget_id')
                ->references('id')->on('project_cost_budgets')
                ->cascadeOnDelete();
        });
    }
};
