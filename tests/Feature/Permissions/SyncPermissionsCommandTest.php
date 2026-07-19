<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Uses the same schema-only SQLite approach as
 * GeneralExchangeAccountValidationTest / CleanOperationalDataCommandTest.
 */
class SyncPermissionsCommandTest extends TestCase
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

    // ---- 5. creates all registered permissions ----

    public function test_it_creates_all_registered_permissions(): void
    {
        $exit = Artisan::call('oms:sync-permissions');

        $this->assertSame(0, $exit);

        foreach (PermissionRegistry::names() as $name) {
            $this->assertTrue(
                Permission::where('name', $name)->where('guard_name', 'web')->exists(),
                "Missing permission: {$name}"
            );
        }

        $this->assertSame(count(PermissionRegistry::names()), Permission::count());
    }

    // ---- 6. creates the five system roles ----

    public function test_it_creates_the_five_system_roles(): void
    {
        Artisan::call('oms:sync-permissions');

        foreach (PermissionRegistry::SYSTEM_ROLES as $roleName) {
            $this->assertTrue(Role::where('name', $roleName)->where('guard_name', 'web')->exists());
        }

        $this->assertSame(5, Role::count());
    }

    // ---- 7. running it twice is idempotent ----

    public function test_running_it_twice_is_idempotent(): void
    {
        Artisan::call('oms:sync-permissions');
        $permissionCountAfterFirst = Permission::count();
        $roleCountAfterFirst = Role::count();

        Artisan::call('oms:sync-permissions');

        $this->assertSame($permissionCountAfterFirst, Permission::count());
        $this->assertSame($roleCountAfterFirst, Role::count());
    }

    // ---- 8. does not delete a custom role ----

    public function test_it_does_not_delete_a_custom_role(): void
    {
        $custom = Role::create(['name' => 'Warehouse Clerk', 'guard_name' => 'web']);

        Artisan::call('oms:sync-permissions');

        $this->assertNotNull(Role::find($custom->id));
    }

    // ---- 9. does not delete a custom permission ----

    public function test_it_does_not_delete_a_custom_permission(): void
    {
        $custom = Permission::create(['name' => 'view finance', 'guard_name' => 'web']);

        Artisan::call('oms:sync-permissions');

        $this->assertNotNull(Permission::find($custom->id));
    }

    // ---- 10. does not modify a custom role's assignments ----

    public function test_it_does_not_modify_a_custom_roles_assignments(): void
    {
        $permission = Permission::create(['name' => 'view finance', 'guard_name' => 'web']);
        $custom = Role::create(['name' => 'Warehouse Clerk', 'guard_name' => 'web']);
        $custom->givePermissionTo($permission);

        Artisan::call('oms:sync-permissions');

        $fresh = $custom->fresh();
        $this->assertTrue($fresh->hasPermissionTo('view finance'));
        $this->assertCount(1, $fresh->permissions);
    }

    // ---- warns when zero users hold the Super Admin role, and never creates one ----

    public function test_it_warns_when_no_user_holds_the_super_admin_role_and_creates_no_users(): void
    {
        $userCountBefore = User::count();

        Artisan::call('oms:sync-permissions');

        $this->assertStringContainsString('Super Admin', Artisan::output());
        $this->assertSame($userCountBefore, User::count());
    }

    public function test_it_does_not_warn_when_a_user_already_holds_the_super_admin_role(): void
    {
        Artisan::call('oms:sync-permissions');

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        Artisan::call('oms:sync-permissions');

        $this->assertStringNotContainsString('تحذير', Artisan::output());
    }
}
