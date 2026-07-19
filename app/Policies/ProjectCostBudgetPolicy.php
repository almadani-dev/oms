<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

/**
 * Model: App\Models\ProjectCostBudget.
 *
 * Governs BOTH:
 *  - ProjectCostBudgetsPaymentResource (disbursement flow, rows where
 *    transaction_id IS NOT NULL);
 *  - ProjectCosts/BudgetsRelationManager (planned-budget rows, transaction_id
 *    IS NULL) — since Filament resolves RelationManager authorization
 *    against the related model's policy, not a per-Resource one.
 *
 * MISLEADING NAME — READ BEFORE CHANGING: the permission prefix below is
 * `project_cost_budgets_payments`, which lexically matches the *other*
 * model (App\Models\ProjectCostBudgetsPayment / execution_payments). This
 * was confirmed during the OMS Permissions Task 2 discovery: the prefix
 * describes what ProjectCostBudgetsPaymentResource *does* (disbursement
 * payments against a project cost budget), not this model's class name. Do
 * not "fix" this to `project_cost_budgets` — the permission strings are
 * already seeded via PermissionSyncService and PermissionRegistry. See the
 * matching note on ProjectCostBudgetsPaymentPolicy.
 */
class ProjectCostBudgetPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'project_cost_budgets_payments';
    }
}
