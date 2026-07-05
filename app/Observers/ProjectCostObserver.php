<?php

namespace App\Observers;

use App\Models\ProjectCost;
use App\Observers\Concerns\MarksProjectSnapshotDirty;

class ProjectCostObserver
{
    use MarksProjectSnapshotDirty;

    public function created(ProjectCost $projectCost): void
    {
        $this->markProjectSnapshotDirty($projectCost->project_id);
    }

    public function updated(ProjectCost $projectCost): void
    {
        $this->markProjectSnapshotDirty($projectCost->project_id);

        // Reassigned to a different project: the old project also lost this
        // cost line, so its snapshot must be invalidated too.
        if ($projectCost->wasChanged('project_id')) {
            $this->markProjectSnapshotDirty($projectCost->getOriginal('project_id'));
        }
    }

    public function deleted(ProjectCost $projectCost): void
    {
        $this->markProjectSnapshotDirty($projectCost->project_id);
    }

    public function restored(ProjectCost $projectCost): void
    {
        $this->markProjectSnapshotDirty($projectCost->project_id);
    }
}
