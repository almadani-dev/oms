<?php

namespace App\Observers\Concerns;

use App\Models\Reports\ProjectFinancialSnapshot;

/**
 * Targeted is_dirty flip only — never recalculates the snapshot itself.
 * A no-op when no snapshot row exists yet for the project (missing
 * snapshots are already covered by the refresh command's dirty/missing mode).
 */
trait MarksProjectSnapshotDirty
{
    protected function markProjectSnapshotDirty(?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }

        ProjectFinancialSnapshot::where('project_id', $projectId)->update(['is_dirty' => true]);
    }
}
