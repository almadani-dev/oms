<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 4: proves `oms:sync-permissions` (Task 1) remains fully compatible
 * with custom roles created by RoleResource/RoleManagementService — it must
 * keep managing the five system roles' permission sets exactly as before,
 * while never touching a custom role's name, permissions, or user
 * assignments.
 */
class SyncCompatibilityTest extends TestCase
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
    }

    // ---- 39. sync-permissions does not modify or delete a custom role ----

    public function test_sync_permissions_does_not_modify_a_custom_roles_name_or_permissions(): void
    {
        Permission::firstOrCreate(['name' => 'accounts.view_any', 'guard_name' => 'web']);
        $customRole = Role::create(['name' => 'Untouched Custom Role', 'guard_name' => 'web']);
        $customRole->syncPermissions(['accounts.view_any']);

        $user = User::factory()->create();
        $user->assignRole($customRole);

        Artisan::call('oms:sync-permissions');

        $fresh = $customRole->fresh();
        $this->assertSame('Untouched Custom Role', $fresh->name);
        $this->assertEqualsCanonicalizing(['accounts.view_any'], $fresh->permissions()->pluck('name')->all());
        $this->assertTrue($user->fresh()->hasRole('Untouched Custom Role'));
    }

    public function test_sync_permissions_does_not_delete_a_custom_role(): void
    {
        $customRole = Role::create(['name' => 'Surviving Custom Role', 'guard_name' => 'web']);

        Artisan::call('oms:sync-permissions');

        $this->assertNotNull(Role::where('name', 'Surviving Custom Role')->first());
    }

    // ---- sync still manages the five system roles' permission sets as before ----

    public function test_sync_permissions_still_manages_the_five_system_roles(): void
    {
        Artisan::call('oms:sync-permissions');

        foreach (PermissionRegistry::SYSTEM_ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $this->assertNotNull($role, "{$roleName} should exist after sync.");

            $expectedPermissionNames = PermissionRegistry::defaultPermissionsForRole($roleName);
            $this->assertEqualsCanonicalizing(
                $expectedPermissionNames,
                $role->permissions()->pluck('name')->all(),
                "{$roleName} should hold exactly its registry-defined default permissions.",
            );
        }
    }

    // ---- running sync again after custom-role creation leaves it unchanged ----

    public function test_running_sync_twice_does_not_disturb_a_custom_role_created_in_between(): void
    {
        Artisan::call('oms:sync-permissions');

        Permission::firstOrCreate(['name' => 'projects.view_any', 'guard_name' => 'web']);
        $customRole = Role::create(['name' => 'Mid Sync Custom Role', 'guard_name' => 'web']);
        $customRole->syncPermissions(['projects.view_any']);

        Artisan::call('oms:sync-permissions');

        $fresh = $customRole->fresh();
        $this->assertSame('Mid Sync Custom Role', $fresh->name);
        $this->assertEqualsCanonicalizing(['projects.view_any'], $fresh->permissions()->pluck('name')->all());
    }
}
