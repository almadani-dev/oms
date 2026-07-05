<?php

namespace App\Observers;

use App\Models\Project;
use App\Observers\Concerns\MarksProjectSnapshotDirty;

class ProjectObserver
{
    use MarksProjectSnapshotDirty;

    public function created(Project $project): void
    {
        $this->markProjectSnapshotDirty($project->id);
    }

    public function updated(Project $project): void
    {
        $this->markProjectSnapshotDirty($project->id);
    }

    public function deleted(Project $project): void
    {
        $this->markProjectSnapshotDirty($project->id);
    }

    public function restored(Project $project): void
    {
        $this->markProjectSnapshotDirty($project->id);
    }
}
