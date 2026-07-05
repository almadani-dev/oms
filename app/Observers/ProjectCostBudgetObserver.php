<?php

namespace App\Observers;

use App\Models\ProjectCostBudget;
use App\Observers\Concerns\MarksProjectSnapshotDirty;
use Illuminate\Support\Facades\DB;

class ProjectCostBudgetObserver
{
    use MarksProjectSnapshotDirty;

    public function created(ProjectCostBudget $budget): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($budget->project_cost_id));
    }

    public function updated(ProjectCostBudget $budget): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($budget->project_cost_id));

        // Reassigned to a different project cost: the old project also lost
        // this budget line, so its snapshot must be invalidated too.
        if ($budget->wasChanged('project_cost_id')) {
            $this->markProjectSnapshotDirty($this->resolveProjectId($budget->getOriginal('project_cost_id')));
        }
    }

    public function deleted(ProjectCostBudget $budget): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($budget->project_cost_id));
    }

    public function restored(ProjectCostBudget $budget): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($budget->project_cost_id));
    }

    /** project_cost_id -> projects_costs.project_id, via a raw query so a soft-deleted parent still resolves. */
    private function resolveProjectId(?int $projectCostId): ?int
    {
        if ($projectCostId === null) {
            return null;
        }

        return DB::table('projects_costs')->where('id', $projectCostId)->value('project_id');
    }
}
