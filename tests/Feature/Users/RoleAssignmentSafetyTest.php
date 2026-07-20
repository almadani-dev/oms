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
 * Task 3: `UserManagementService::assignableRoleNames()` (form options) and
 * its server-side revalidation inside createUser()/updateUser() — a
 * non-Super-Admin may only hand out a role whose permissions are entirely
 * within their own effective set and contain no protected system
 * permission, regardless of what the client submits.
 */
class RoleAssignmentSafetyTest extends TestCase
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

        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);
    }

    // ---- 27. non-Super-Admin role selector excludes Super Admin ----

    public function test_non_super_admin_assignable_roles_exclude_super_admin(): void
    {
        $actor = $this->actorWithPermissions(['users.update']);

        $this->assertNotContains(
            PermissionRegistry::SUPER_ADMIN,
            app(UserManagementService::class)->assignableRoleNames($actor)
        );
    }

    // ---- 28. submitted Super Admin role is rejected server-side ----

    public function test_submitted_super_admin_role_is_rejected_server_side(): void
    {
        $actor = $this->actorWithPermissions(['users.create']);

        try {
            app(UserManagementService::class)->createUser($actor, [
                'name' => 'Crafted',
                'email' => 'crafted-'.uniqid().'@example.com',
                'password' => 'Some-Password-123',
                'roles' => [PermissionRegistry::SUPER_ADMIN],
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }
    }

    // ---- 29. non-Super-Admin cannot assign a role containing permissions they don't hold ----

    public function test_non_super_admin_cannot_assign_a_role_with_permissions_they_lack(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'accounts.view_any']);
        $role = $this->roleWithPermissions('Broader Role', ['accounts.view_any', 'accounts.delete']);

        $this->assertNotContains($role->name, app(UserManagementService::class)->assignableRoleNames($actor));

        try {
            app(UserManagementService::class)->createUser($actor, [
                'name' => 'Crafted',
                'email' => 'crafted-'.uniqid().'@example.com',
                'password' => 'Some-Password-123',
                'roles' => [$role->name],
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }
    }

    // ---- 30. non-Super-Admin cannot assign a role containing roles.*/permissions.* ----

    public function test_non_super_admin_cannot_assign_a_role_with_protected_permissions(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'roles.view_any', 'permissions.view_any']);

        $rolesGuardRole = $this->roleWithPermissions('Roles Guard', ['roles.view_any']);
        $permissionsGuardRole = $this->roleWithPermissions('Permissions Guard', ['permissions.view_any']);
        $assignSuperAdminRole = $this->roleWithPermissions('Assign Guard', ['users.assign_super_admin']);

        $allowed = app(UserManagementService::class)->assignableRoleNames($actor);
        $this->assertNotContains($rolesGuardRole->name, $allowed);
        $this->assertNotContains($permissionsGuardRole->name, $allowed);
        $this->assertNotContains($assignSuperAdminRole->name, $allowed);

        try {
            app(UserManagementService::class)->createUser($actor, [
                'name' => 'Crafted',
                'email' => 'crafted-'.uniqid().'@example.com',
                'password' => 'Some-Password-123',
                'roles' => [$rolesGuardRole->name],
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }
    }

    // ---- 31. valid lower/equal normal role assignment succeeds ----

    public function test_lower_or_equal_normal_role_assignment_succeeds(): void
    {
        $actor = $this->actorWithPermissions(['users.create', 'accounts.view_any', 'accounts.view']);
        $role = $this->roleWithPermissions('Safe Role', ['accounts.view_any', 'accounts.view']);

        $this->assertContains($role->name, app(UserManagementService::class)->assignableRoleNames($actor));

        $created = app(UserManagementService::class)->createUser($actor, [
            'name' => 'Fine Person',
            'email' => 'fine-'.uniqid().'@example.com',
            'password' => 'Some-Password-123',
            'roles' => [$role->name],
        ]);

        $this->assertTrue($created->fresh()->hasRole($role->name));
    }

    // ---- 32. Super Admin can assign any normal role ----

    public function test_super_admin_can_assign_any_normal_role(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole(PermissionRegistry::SUPER_ADMIN);

        $role = $this->roleWithPermissions('Any Role', ['accounts.view_any', 'accounts.delete', 'projects.delete']);

        $allowed = app(UserManagementService::class)->assignableRoleNames($actor);
        $this->assertContains($role->name, $allowed);
        $this->assertContains(PermissionRegistry::SUPER_ADMIN, $allowed);

        $created = app(UserManagementService::class)->createUser($actor, [
            'name' => 'Any Person',
            'email' => 'any-'.uniqid().'@example.com',
            'password' => 'Some-Password-123',
            'roles' => [$role->name],
        ]);

        $this->assertTrue($created->fresh()->hasRole($role->name));
    }

    private function roleWithPermissions(string $roleName, array $permissionNames): Role
    {
        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
        $role->syncPermissions($permissionNames);

        return $role;
    }

    private function actorWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create();

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}
