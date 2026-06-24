<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds only the indexes that are NOT already provided by foreign-key
     * constraints (constrained() auto-indexes single FK columns). FK columns
     * such as projects.donor_id/project_super_id/project_status_id,
     * projects_costs.project_id/currency_id, etc. are intentionally skipped.
     *
     * Each index is guarded by an existence check so the migration is safe to
     * (re)run even if some indexes were already created.
     */
    public function up(): void
    {
        $this->addIndex('projects', ['approval_date'], 'projects_approval_date_index');
        $this->addIndex('projects', ['start_date'], 'projects_start_date_index');
        $this->addIndex('projects', ['end_date'], 'projects_end_date_index');

        $this->addIndex('projects_costs', ['project_id', 'currency_id'], 'projects_costs_project_id_currency_id_index');

        $this->addIndex('project_cost_receipts', ['project_cost_id', 'currency_id'], 'project_cost_receipts_project_cost_id_currency_id_index');
        $this->addIndex('project_cost_receipts', ['date'], 'project_cost_receipts_date_index');

        $this->addIndex('project_cost_budgets_payments', ['project_cost_budget_id', 'currency_id'], 'pcbp_budget_currency_index');
        $this->addIndex('project_cost_budgets_payments', ['date'], 'project_cost_budgets_payments_date_index');
    }

    public function down(): void
    {
        $this->dropIndex('projects', 'projects_approval_date_index');
        $this->dropIndex('projects', 'projects_start_date_index');
        $this->dropIndex('projects', 'projects_end_date_index');
        $this->dropIndex('projects_costs', 'projects_costs_project_id_currency_id_index');
        $this->dropIndex('project_cost_receipts', 'project_cost_receipts_project_cost_id_currency_id_index');
        $this->dropIndex('project_cost_receipts', 'project_cost_receipts_date_index');
        $this->dropIndex('project_cost_budgets_payments', 'pcbp_budget_currency_index');
        $this->dropIndex('project_cost_budgets_payments', 'project_cost_budgets_payments_date_index');
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->contains($index);
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns, $name) {
            $t->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($name) {
            $t->dropIndex($name);
        });
    }
};
