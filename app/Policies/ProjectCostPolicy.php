<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

/**
 * Also governs Projects/CostsRelationManager (relationship "costs" on
 * Project => ProjectCost) — Filament resolves RelationManager authorization
 * against the related model's policy, so viewing a Project does not by
 * itself grant project_costs.create/update/delete.
 */
class ProjectCostPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'project_costs';
    }
}
