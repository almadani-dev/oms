<?php

namespace Tests\Feature\Permissions;

use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Uses the same schema-only SQLite approach as
 * GeneralExchangeAccountValidationTest / CleanOperationalDataCommandTest.
 */
class SystemRoleDefaultPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        Artisan::call('oms:sync-permissions');
    }

    private function permissionNamesFor(string $role): array
    {
        return Role::where('name', $role)->first()->permissions()->pluck('name')->all();
    }

    // ---- 11. Super Admin receives every registered permission ----

    public function test_super_admin_receives_every_registered_permission(): void
    {
        $names = $this->permissionNamesFor(PermissionRegistry::SUPER_ADMIN);

        $this->assertSame(count(PermissionRegistry::names()), count($names));

        foreach (PermissionRegistry::names() as $name) {
            $this->assertContains($name, $names);
        }
    }

    // ---- 12. Admin does not receive roles.*, permissions.*, or users.assign_super_admin ----

    public function test_admin_does_not_receive_roles_permissions_or_user_management(): void
    {
        $names = $this->permissionNamesFor(PermissionRegistry::ADMIN);

        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'roles.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'permissions.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'users.')));
        $this->assertNotContains('users.assign_super_admin', $names);

        // Still broad operational access.
        $this->assertContains('accounts.create', $names);
        $this->assertContains('projects.delete', $names);
    }

    // ---- 13. Accountant receives financial permissions but no user/role management ----

    public function test_accountant_receives_financial_permissions_but_no_user_or_role_management(): void
    {
        $names = $this->permissionNamesFor(PermissionRegistry::ACCOUNTANT);

        $this->assertContains('accounts.delete', $names);
        $this->assertContains('project_cost_budgets_payments.create', $names);
        $this->assertContains('execution_payments.update', $names);
        $this->assertContains('reports.trial_balance.export', $names);
        $this->assertContains('transactions.view', $names);
        $this->assertNotContains('transactions.create', $names);

        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'users.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'roles.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'permissions.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'projects.')));
    }

    // ---- 14. Project Manager receives project permissions but no accounting administration ----

    public function test_project_manager_receives_project_permissions_but_no_accounting_administration(): void
    {
        $names = $this->permissionNamesFor(PermissionRegistry::PROJECT_MANAGER);

        $this->assertContains('projects.create', $names);
        $this->assertContains('project_costs.update', $names);
        $this->assertContains('project_supers.delete', $names);
        $this->assertContains('reports.projects_general_financial.view', $names);
        $this->assertNotContains('reports.projects_general_financial.export', $names);

        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'accounts.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'general_expenses.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'general_exchanges.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'users.')));
    }

    // ---- 15. Viewer receives no create/update/delete/restore/export permissions ----

    public function test_viewer_receives_no_create_update_delete_restore_or_export_permissions(): void
    {
        $names = $this->permissionNamesFor(PermissionRegistry::VIEWER);

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $operation = substr($name, strrpos($name, '.') + 1);
            $this->assertContains($operation, ['view_any', 'view'], "Viewer unexpectedly has: {$name}");
        }

        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'users.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'roles.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'permissions.')));
        $this->assertEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'reports.')));
    }
}
