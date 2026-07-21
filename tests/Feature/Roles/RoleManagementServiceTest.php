<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use App\Services\Roles\RoleManagementService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 4: direct service tests for RoleManagementService's transactional
 * invariants — system-role protection, permission validation/guard scoping,
 * privilege-subset checks, self-assignment protection, and unused-role-only
 * deletion. Exercised directly (fast, precise) since this service is the
 * authoritative enforcement layer, mirroring the Task 3
 * PrivilegeSubsetProtectionTest/SelfProtectionTest approach for users.
 */
class RoleManagementServiceTest extends TestCase
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

    // ---- 9. all five system roles are marked protected ----

    public function test_all_five_system_roles_are_marked_protected(): void
    {
        $service = app(RoleManagementService::class);

        foreach (PermissionRegistry::SYSTEM_ROLES as $roleName) {
            $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
            $this->assertTrue($service->isSystemRole($role), "{$roleName} should be a protected system role.");
        }
    }

    public function test_a_custom_role_is_not_marked_protected(): void
    {
        $service = app(RoleManagementService::class);
        $role = Role::create(['name' => 'Custom Reviewer', 'guard_name' => 'web']);

        $this->assertFalse($service->isSystemRole($role));
    }

    // ---- 15/16. Super Admin creates a custom role and it receives the selected permissions ----

    public function test_super_admin_creates_a_custom_role_with_selected_permissions(): void
    {
        $actor = $this->superAdmin();
        $this->permission('accounts.view_any');
        $this->permission('accounts.view');

        $role = app(RoleManagementService::class)->createRole($actor, [
            'name' => 'Custom Reviewer',
            'permissions' => ['accounts.view_any', 'accounts.view'],
        ]);

        $this->assertSame('Custom Reviewer', $role->name);
        $this->assertSame('web', $role->guard_name);
        $this->assertEqualsCanonicalizing(
            ['accounts.view_any', 'accounts.view'],
            $role->fresh()->permissions()->pluck('name')->all(),
        );
    }

    // ---- 17. duplicate role name is rejected ----

    public function test_duplicate_role_name_is_rejected(): void
    {
        $actor = $this->superAdmin();
        Role::create(['name' => 'Existing Role', 'guard_name' => 'web']);

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->createRole($actor, ['name' => 'Existing Role', 'permissions' => []]);
    }

    // ---- 18. protected system-role name is rejected ----

    public function test_protected_system_role_name_is_rejected_on_create(): void
    {
        $actor = $this->superAdmin();

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->createRole($actor, [
            'name' => PermissionRegistry::ADMIN,
            'permissions' => [],
        ]);
    }

    public function test_renaming_a_custom_role_to_a_protected_system_role_name_is_rejected(): void
    {
        $actor = $this->superAdmin();
        $role = Role::create(['name' => 'Renameable Role', 'guard_name' => 'web']);

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->updateRole($actor, $role, ['name' => PermissionRegistry::VIEWER]);
    }

    // ---- 19. guard cannot be changed through crafted data ----

    public function test_guard_cannot_be_changed_through_crafted_data(): void
    {
        $actor = $this->superAdmin();

        $role = app(RoleManagementService::class)->createRole($actor, [
            'name' => 'Guard Test Role',
            'guard_name' => 'api',
            'permissions' => [],
        ]);

        $this->assertSame('web', $role->fresh()->guard_name);
    }

    // ---- 20. authorized update changes a custom role safely ----

    public function test_authorized_update_changes_a_custom_role_safely(): void
    {
        $actor = $this->superAdmin();
        $this->permission('accounts.view_any');
        $role = Role::create(['name' => 'Old Name', 'guard_name' => 'web']);

        $updated = app(RoleManagementService::class)->updateRole($actor, $role, [
            'name' => 'New Name',
            'permissions' => ['accounts.view_any'],
        ]);

        $this->assertSame('New Name', $updated->fresh()->name);
        $this->assertEqualsCanonicalizing(['accounts.view_any'], $updated->fresh()->permissions()->pluck('name')->all());
    }

    // ---- 21. unknown permission name is rejected ----

    public function test_unknown_permission_name_is_rejected(): void
    {
        $actor = $this->superAdmin();

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->createRole($actor, [
            'name' => 'Unknown Permission Role',
            'permissions' => ['totally.fake.permission'],
        ]);
    }

    // ---- 22. permission from the wrong guard is rejected ----

    public function test_permission_from_the_wrong_guard_is_rejected(): void
    {
        $actor = $this->superAdmin();
        Permission::create(['name' => 'accounts.view_any', 'guard_name' => 'api']);

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->createRole($actor, [
            'name' => 'Wrong Guard Role',
            'permissions' => ['accounts.view_any'],
        ]);
    }

    // ---- 23. existing custom/unregistered permission is preserved during an update that omits it ----

    public function test_custom_unregistered_permission_is_preserved_when_permissions_key_is_omitted(): void
    {
        $actor = $this->superAdmin();
        $custom = Permission::create(['name' => 'legacy.custom_action', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'Legacy Role', 'guard_name' => 'web']);
        $role->givePermissionTo($custom);

        $updated = app(RoleManagementService::class)->updateRole($actor, $role, ['name' => 'Legacy Role Renamed']);

        $this->assertEqualsCanonicalizing(['legacy.custom_action'], $updated->fresh()->permissions()->pluck('name')->all());
    }

    public function test_super_admin_may_explicitly_resubmit_a_custom_unregistered_permission(): void
    {
        $actor = $this->superAdmin();
        Permission::create(['name' => 'legacy.custom_action', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'Legacy Role', 'guard_name' => 'web']);

        $updated = app(RoleManagementService::class)->updateRole($actor, $role, [
            'permissions' => ['legacy.custom_action'],
        ]);

        $this->assertEqualsCanonicalizing(['legacy.custom_action'], $updated->fresh()->permissions()->pluck('name')->all());
    }

    // ---- 24. non-Super-Admin sees only assignable permissions ----

    public function test_non_super_admin_assignable_permissions_excludes_protected_and_unheld_names(): void
    {
        $actor = $this->actorWithPermissions(['roles.create', 'accounts.view_any', 'roles.view_any', 'users.assign_super_admin']);

        $assignable = app(RoleManagementService::class)->assignablePermissionNames($actor);

        $this->assertEqualsCanonicalizing(['accounts.view_any'], $assignable);
    }

    public function test_super_admin_assignable_permissions_includes_every_existing_permission(): void
    {
        $actor = $this->superAdmin();
        $this->permission('accounts.view_any');
        $this->permission('roles.view_any');
        $this->permission('users.assign_super_admin');

        $assignable = app(RoleManagementService::class)->assignablePermissionNames($actor);

        $this->assertContains('accounts.view_any', $assignable);
        $this->assertContains('roles.view_any', $assignable);
        $this->assertContains('users.assign_super_admin', $assignable);
    }

    // ---- 25. cannot add a permission the actor does not hold ----

    public function test_non_super_admin_cannot_add_a_permission_they_do_not_hold(): void
    {
        $actor = $this->actorWithPermissions(['roles.create', 'accounts.view_any']);

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->createRole($actor, [
            'name' => 'Overreaching Role',
            'permissions' => ['accounts.delete'],
        ]);
    }

    // ---- 26. cannot add users.assign_super_admin ----

    public function test_non_super_admin_cannot_add_assign_super_admin_permission(): void
    {
        $actor = $this->actorWithPermissions(['roles.create', 'users.assign_super_admin']);

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->createRole($actor, [
            'name' => 'Escalation Attempt',
            'permissions' => ['users.assign_super_admin'],
        ]);
    }

    // ---- 27. cannot add roles.* ----

    public function test_non_super_admin_cannot_add_roles_permissions(): void
    {
        $actor = $this->actorWithPermissions(['roles.create', 'roles.view_any', 'roles.delete']);

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->createRole($actor, [
            'name' => 'Roles Escalation Attempt',
            'permissions' => ['roles.delete'],
        ]);
    }

    // ---- 28. cannot add permissions.* ----

    public function test_non_super_admin_cannot_add_permissions_permissions(): void
    {
        $actor = $this->actorWithPermissions(['roles.create', 'permissions.view_any']);

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->createRole($actor, [
            'name' => 'Permissions Escalation Attempt',
            'permissions' => ['permissions.view_any'],
        ]);
    }

    // ---- 29. cannot modify a role whose permissions exceed the actor's ----

    public function test_non_super_admin_cannot_modify_a_role_whose_permissions_exceed_theirs(): void
    {
        $actor = $this->actorWithPermissions(['roles.update', 'accounts.view_any']);
        $role = $this->roleWithPermissions('Overpowered Role', ['accounts.view_any', 'accounts.delete']);

        $this->assertFalse(app(RoleManagementService::class)->canManageRole($actor, $role));

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->updateRole($actor, $role, ['name' => 'Renamed']);
    }

    // ---- 30. can manage an equal/lower custom role when otherwise authorized ----

    public function test_non_super_admin_can_manage_an_equal_or_lower_custom_role(): void
    {
        $actor = $this->actorWithPermissions(['roles.update', 'accounts.view_any', 'accounts.delete']);
        $role = $this->roleWithPermissions('Manageable Role', ['accounts.view_any']);

        $this->assertTrue(app(RoleManagementService::class)->canManageRole($actor, $role));

        $updated = app(RoleManagementService::class)->updateRole($actor, $role, ['name' => 'Manageable Role Renamed']);
        $this->assertSame('Manageable Role Renamed', $updated->fresh()->name);
    }

    // ---- 31. rejected operation leaves role name and permission pivots unchanged ----

    public function test_rejected_update_leaves_role_and_permission_pivots_unchanged(): void
    {
        $actor = $this->actorWithPermissions(['roles.update', 'accounts.view_any']);
        $role = $this->roleWithPermissions('Untouchable Role', ['accounts.view_any', 'accounts.delete']);

        $originalName = $role->name;
        $originalPermissionNames = $role->permissions()->pluck('name')->sort()->values()->all();

        try {
            app(RoleManagementService::class)->updateRole($actor, $role, ['name' => 'Hijacked Name', 'permissions' => []]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        $fresh = $role->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertSame($originalPermissionNames, $fresh->permissions()->pluck('name')->sort()->values()->all());
    }

    // ---- 32. a user cannot update a role assigned to themselves ----

    public function test_user_cannot_update_a_role_assigned_to_themselves(): void
    {
        $actor = $this->actorWithPermissions(['roles.update']);
        $role = Role::create(['name' => 'Self Assigned Role', 'guard_name' => 'web']);
        $actor->assignRole($role);

        $this->assertFalse(app(RoleManagementService::class)->canManageRole($actor, $role));

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->updateRole($actor, $role, ['name' => 'New Name']);
    }

    public function test_super_admin_cannot_update_a_role_assigned_to_themselves(): void
    {
        $actor = $this->superAdmin();
        $role = Role::create(['name' => 'Self Assigned Custom Role', 'guard_name' => 'web']);
        $actor->assignRole($role);

        $this->assertFalse(app(RoleManagementService::class)->canManageRole($actor, $role));

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->updateRole($actor, $role, ['name' => 'New Name']);
    }

    // ---- 33. a user cannot delete a role assigned to themselves ----

    public function test_user_cannot_delete_a_role_assigned_to_themselves(): void
    {
        $actor = $this->actorWithPermissions(['roles.delete']);
        $role = Role::create(['name' => 'Self Assigned Role', 'guard_name' => 'web']);
        $actor->assignRole($role);

        $this->expectException(ValidationException::class);

        app(RoleManagementService::class)->deleteRole($actor, $role);
    }

    // ---- 34. role assigned to any user cannot be deleted ----

    public function test_role_assigned_to_a_user_cannot_be_deleted(): void
    {
        $actor = $this->superAdmin();
        $role = Role::create(['name' => 'In Use Role', 'guard_name' => 'web']);
        $otherUser = User::factory()->create();
        $otherUser->assignRole($role);

        $this->expectException(ValidationException::class);

        try {
            app(RoleManagementService::class)->deleteRole($actor, $role);
        } finally {
            $this->assertNotNull(Role::find($role->id));
        }
    }

    // ---- 35. unused manageable custom role can be deleted ----

    public function test_unused_manageable_custom_role_can_be_deleted(): void
    {
        $actor = $this->superAdmin();
        $role = Role::create(['name' => 'Unused Role', 'guard_name' => 'web']);

        app(RoleManagementService::class)->deleteRole($actor, $role);

        $this->assertNull(Role::find($role->id));
    }

    // ---- actor missing the roles.create/update/delete ability is rejected regardless of target ----

    public function test_actor_without_roles_create_ability_is_rejected(): void
    {
        $actor = User::factory()->create();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(RoleManagementService::class)->createRole($actor, ['name' => 'Should Not Exist', 'permissions' => []]);
    }

    // ---- 38. permission-cache changes take effect immediately after a role update ----

    public function test_permission_cache_reflects_immediately_after_role_update(): void
    {
        $actor = $this->superAdmin();
        $this->permission('accounts.view_any');
        $role = Role::create(['name' => 'Cache Test Role', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertFalse($user->can('accounts.view_any'));

        app(RoleManagementService::class)->updateRole($actor, $role, ['permissions' => ['accounts.view_any']]);

        $this->assertTrue($user->fresh()->can('accounts.view_any'));
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

    private function roleWithPermissions(string $roleName, array $permissionNames): Role
    {
        foreach ($permissionNames as $name) {
            $this->permission($name);
        }

        $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
        $role->syncPermissions($permissionNames);

        return $role;
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }
}
