<?php

namespace Tests\Feature\Users;

use App\Models\User;
use App\Services\Users\UserManagementService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 3, second review round: `UserManagementService::canManageUser()` must
 * compare the *full effective privilege set* of the target against the
 * actor — not just whether the target holds the exact `Super Admin` role.
 * Without this, a non-Super-Admin operator holding only `users.update`
 * could edit the email/password/status of a more-powerful Admin account
 * they don't otherwise outrank. These tests exercise the service directly
 * (fast, precise) since this is the authoritative enforcement layer.
 */
class PrivilegeSubsetProtectionTest extends TestCase
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

    // ---- 1. users.update-only actor cannot change name/email/password of a more-privileged target ----

    public function test_update_only_actor_cannot_change_fields_of_a_more_privileged_target(): void
    {
        $actor = $this->actorWithPermissions(['users.update']);
        $target = $this->targetWithRolePermissions(['accounts.view_any', 'accounts.delete']);

        $originalEmail = $target->email;
        $originalPasswordHash = $target->password;
        $originalName = $target->name;

        try {
            app(UserManagementService::class)->updateUser($actor, $target, [
                'name' => 'Changed Name',
                'email' => 'changed-'.uniqid().'@example.com',
                'password' => 'New-Password-123',
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $fresh = $target->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertSame($originalEmail, $fresh->email);
        $this->assertSame($originalPasswordHash, $fresh->password);
    }

    // ---- 2. users.delete actor cannot delete a more-privileged target ----

    public function test_delete_actor_cannot_delete_a_more_privileged_target(): void
    {
        $actor = $this->actorWithPermissions(['users.delete']);
        $target = $this->targetWithRolePermissions(['accounts.delete']);

        try {
            app(UserManagementService::class)->deleteUser($actor, $target);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse($target->fresh()->trashed());
    }

    // ---- 3. users.restore actor cannot restore a more-privileged (trashed) target ----

    public function test_restore_actor_cannot_restore_a_more_privileged_target(): void
    {
        $actor = $this->actorWithPermissions(['users.restore']);
        $target = $this->targetWithRolePermissions(['accounts.delete']);
        $target->delete();

        try {
            app(UserManagementService::class)->restoreUser($actor, $target);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertTrue($target->fresh()->trashed());
    }

    // ---- 4. a target holding a protected permission directly is unmanageable ----

    public function test_target_with_protected_direct_permission_is_unmanageable(): void
    {
        $actor = $this->actorWithPermissions(['users.update', 'roles.view_any']);
        $target = $this->targetWithDirectPermissions(['roles.view_any']);

        $this->assertFalse(app(UserManagementService::class)->canManageUser($actor, $target));
    }

    // ---- 5. a target whose excess permission comes from a role is equally unmanageable ----

    public function test_target_whose_excess_permission_comes_from_a_role_is_unmanageable(): void
    {
        $actor = $this->actorWithPermissions(['users.update']);
        $target = $this->targetWithRolePermissions(['projects.delete']);

        $this->assertFalse(app(UserManagementService::class)->canManageUser($actor, $target));
    }

    // ---- 6. a target with equal-or-lower effective permissions is manageable ----

    public function test_target_with_equal_or_lower_permissions_is_manageable(): void
    {
        $actor = $this->actorWithPermissions(['users.update', 'accounts.view_any']);
        $target = $this->targetWithRolePermissions(['accounts.view_any']);

        $this->assertTrue(app(UserManagementService::class)->canManageUser($actor, $target));

        $updated = app(UserManagementService::class)->updateUser($actor, $target, ['name' => 'Renamed Target']);
        $this->assertSame('Renamed Target', $updated->fresh()->name);
    }

    // ---- 7. a Super Admin actor can manage a more-privileged normal user ----

    public function test_super_admin_can_manage_a_more_privileged_normal_user(): void
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $actor = User::factory()->create();
        $actor->assignRole(PermissionRegistry::SUPER_ADMIN);

        $target = $this->targetWithRolePermissions(['accounts.delete', 'projects.delete']);

        $this->assertTrue(app(UserManagementService::class)->canManageUser($actor, $target));
    }

    // ---- 8. rejected attempts leave role and direct permission pivots unchanged ----

    public function test_rejected_update_leaves_role_and_direct_permission_pivots_unchanged(): void
    {
        $actor = $this->actorWithPermissions(['users.update']);
        $target = $this->targetWithRolePermissions(['accounts.delete']);
        $target->givePermissionTo($this->permission('projects.view_any'));

        $originalRoleNames = $target->roles()->pluck('name')->sort()->values()->all();
        $originalDirectPermissionNames = $target->permissions()->pluck('name')->sort()->values()->all();

        try {
            app(UserManagementService::class)->updateUser($actor, $target, ['roles' => []]);
        } catch (ValidationException) {
            // expected
        }

        $fresh = $target->fresh();
        $this->assertSame($originalRoleNames, $fresh->roles()->pluck('name')->sort()->values()->all());
        $this->assertSame($originalDirectPermissionNames, $fresh->permissions()->pluck('name')->sort()->values()->all());
    }

    private function permission(string $name): Permission
    {
        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function actorWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create();

        foreach ($permissionNames as $name) {
            $user->givePermissionTo($this->permission($name));
        }

        return $user;
    }

    private function targetWithRolePermissions(array $permissionNames): User
    {
        foreach ($permissionNames as $name) {
            $this->permission($name);
        }

        $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissionNames);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function targetWithDirectPermissions(array $permissionNames): User
    {
        $user = User::factory()->create();

        foreach ($permissionNames as $name) {
            $user->givePermissionTo($this->permission($name));
        }

        return $user;
    }
}
