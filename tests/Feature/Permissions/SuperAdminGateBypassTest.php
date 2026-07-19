<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Uses the same schema-only SQLite approach as
 * GeneralExchangeAccountValidationTest / CleanOperationalDataCommandTest.
 */
class SuperAdminGateBypassTest extends TestCase
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

    // ---- 1. Super Admin bypass grants an arbitrary registered permission ----

    public function test_super_admin_gate_before_grants_an_arbitrary_registered_permission(): void
    {
        Permission::create(['name' => 'accounts.delete', 'guard_name' => 'web']);
        $superAdmin = Role::create(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole($superAdmin);

        $this->assertTrue($user->can('accounts.delete'));
    }

    // ---- 2. normal user without the permission is denied ----

    public function test_normal_user_without_permission_is_denied(): void
    {
        Permission::create(['name' => 'accounts.delete', 'guard_name' => 'web']);

        $user = User::factory()->create();

        $this->assertFalse($user->can('accounts.delete'));
    }

    // ---- 3. normal user with the permission assigned is allowed ----

    public function test_normal_user_with_assigned_permission_is_allowed(): void
    {
        $permission = Permission::create(['name' => 'accounts.delete', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        $this->assertTrue($user->can('accounts.delete'));
    }

    // ---- 4. unauthenticated users are not bypassed ----

    public function test_unauthenticated_user_is_not_bypassed(): void
    {
        Permission::create(['name' => 'accounts.delete', 'guard_name' => 'web']);

        $this->assertFalse(Gate::forUser(null)->allows('accounts.delete'));
    }
}
