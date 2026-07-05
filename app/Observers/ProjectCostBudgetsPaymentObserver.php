<?php

namespace App\Observers;

use App\Models\ProjectCostBudgetsPayment;
use App\Observers\Concerns\MarksProjectSnapshotDirty;
use Illuminate\Support\Facades\DB;

class ProjectCostBudgetsPaymentObserver
{
    use MarksProjectSnapshotDirty;

    public function created(ProjectCostBudgetsPayment $payment): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($payment->project_cost_budget_id));
    }

    public function updated(ProjectCostBudgetsPayment $payment): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($payment->project_cost_budget_id));

        // Reassigned to a different project cost budget: the old project also
        // lost this payment, so its snapshot must be invalidated too.
        if ($payment->wasChanged('project_cost_budget_id')) {
            $this->markProjectSnapshotDirty($this->resolveProjectId($payment->getOriginal('project_cost_budget_id')));
        }
    }

    public function deleted(ProjectCostBudgetsPayment $payment): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($payment->project_cost_budget_id));
    }

    public function restored(ProjectCostBudgetsPayment $payment): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($payment->project_cost_budget_id));
    }

    /**
     * project_cost_budget_id -> project_cost_budgets.project_cost_id -> projects_costs.project_id,
     * via a raw join so soft-deleted parents still resolve.
     */
    private function resolveProjectId(?int $projectCostBudgetId): ?int
    {
        if ($projectCostBudgetId === null) {
            return null;
        }

        return DB::table('project_cost_budgets as b')
            ->join('projects_costs as c', 'c.id', '=', 'b.project_cost_id')
            ->where('b.id', $projectCostBudgetId)
            ->value('c.project_id');
    }
}
