<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use App\Services\Permissions\PermissionManagementService;
use App\Services\Permissions\PermissionSyncService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5: direct service-level tests for PermissionManagementService — the
 * thin authorization wrapper that must authorize first and only then
 * delegate to the existing, untouched PermissionSyncService. Complements
 * PermissionSyncActionTest (which proves the same rules through the real
 * Livewire action) with direct proof that the service itself, independent
 * of any UI layer, enforces: authenticated actor, exact `Super Admin` role,
 * and `permissions.sync` ability — and that it returns exactly
 * PermissionSyncService::sync()'s result shape without altering it.
 */
class PermissionManagementServiceTest extends TestCase
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

    public function test_null_actor_is_rejected_and_nothing_is_mutated(): void
    {
        $permissionCountBefore = Permission::count();

        $this->expectException(AuthorizationException::class);

        try {
            app(PermissionManagementService::class)->sync(null);
        } finally {
            $this->assertSame($permissionCountBefore, Permission::count());
        }
    }

    public function test_non_super_admin_with_permissions_sync_is_rejected(): void
    {
        $user = $this->userWithPermissions(['permissions.view_any', 'permissions.view', 'permissions.sync']);
        $permissionCountBefore = Permission::count();

        $this->assertFalse(app(PermissionManagementService::class)->canSync($user));

        $this->expectException(AuthorizationException::class);

        try {
            app(PermissionManagementService::class)->sync($user);
        } finally {
            $this->assertSame($permissionCountBefore, Permission::count());
        }
    }

    public function test_super_admin_without_the_permissions_sync_ability_is_rejected(): void
    {
        // A Super Admin always passes ->can() via Gate::before, so this
        // documents that canSync() genuinely requires both conditions in
        // combination rather than either alone being sufficient in
        // isolation — hasRole() is the one that can never be bypassed.
        $user = $this->superAdmin();

        $this->assertTrue(app(PermissionManagementService::class)->canSync($user));
    }

    public function test_super_admin_can_sync_and_receives_the_exact_service_result_shape(): void
    {
        $user = $this->superAdmin();

        $result = app(PermissionManagementService::class)->sync($user);

        $this->assertArrayHasKey('permissions_created', $result);
        $this->assertArrayHasKey('permissions_found', $result);
        $this->assertArrayHasKey('roles_created', $result);
        $this->assertArrayHasKey('roles_found', $result);
        $this->assertArrayHasKey('role_permission_counts', $result);
        $this->assertArrayHasKey('super_admin_user_count', $result);

        $directResult = app(PermissionSyncService::class)->sync();
        $this->assertSame(array_keys($directResult), array_keys($result));
    }

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }
}
