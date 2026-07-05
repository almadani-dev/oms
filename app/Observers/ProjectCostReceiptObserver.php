<?php

namespace App\Observers;

use App\Models\ProjectCostReceipt;
use App\Observers\Concerns\MarksProjectSnapshotDirty;
use Illuminate\Support\Facades\DB;

class ProjectCostReceiptObserver
{
    use MarksProjectSnapshotDirty;

    public function created(ProjectCostReceipt $receipt): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($receipt->project_cost_id));
    }

    public function updated(ProjectCostReceipt $receipt): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($receipt->project_cost_id));

        // Reassigned to a different project cost: the old project also lost
        // this receipt, so its snapshot must be invalidated too.
        if ($receipt->wasChanged('project_cost_id')) {
            $this->markProjectSnapshotDirty($this->resolveProjectId($receipt->getOriginal('project_cost_id')));
        }
    }

    public function deleted(ProjectCostReceipt $receipt): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($receipt->project_cost_id));
    }

    public function restored(ProjectCostReceipt $receipt): void
    {
        $this->markProjectSnapshotDirty($this->resolveProjectId($receipt->project_cost_id));
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
