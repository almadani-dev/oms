<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCrud;

/**
 * Model: App\Models\ProjectCostBudgetsPayment — used by
 * ExecutionPaymentResource (execution payments drawn down against a
 * ProjectCostBudget's remaining balance, via project_cost_budget_id).
 *
 * MISLEADING NAME — READ BEFORE CHANGING: the permission prefix below is
 * `execution_payments`, NOT `project_cost_budgets_payments`, even though
 * this model's own class name is the closer lexical match to the latter.
 * Confirmed during the OMS Permissions Task 2 discovery:
 * ExecutionPaymentResource's model is this one, while
 * ProjectCostBudgetsPaymentResource's model is the *different*
 * App\Models\ProjectCostBudget (see ProjectCostBudgetPolicy). The two
 * resources do not share a table — there is no row overlap.
 */
class ProjectCostBudgetsPaymentPolicy
{
    use AuthorizesCrud;

    public function permissionModule(): string
    {
        return 'execution_payments';
    }
}
