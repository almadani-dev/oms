<?php

namespace Tests\Feature\Roles;

use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use App\Services\Roles\RoleManagementService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 4 pre-commit review finding: the final Edit/Delete authorization
 * decision for a custom role must be `operation ability AND canManageRole()`
 * — never either check alone. `RoleResource::canEdit()`/`canDelete()` already
 * combine `$user->can('roles.update'|'roles.delete')` with
 * `RoleManagementService::canManageRole()`; this file adds the explicit,
 * isolated proofs the review requested for every combination of the two
 * checks, plus the Super-Admin-eligible-custom-role and
 * rejected-operation-leaves-data-unchanged cases.
 */
class RoleOperationAndTargetSafetyTest extends TestCase
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

        URL::forceRootUrl('http://localhost');
    }

    // ---- 1. canManageRole() satisfied but roles.update missing -> 403 on Edit URL ----

    public function test_actor_satisfying_can_manage_role_but_missing_roles_update_is_403d_on_edit_url(): void
    {
        $role = $this->roleWithPermissions('Manageable But No Update Ability', ['accounts.view_any']);
        $actor = $this->userWithPermissions(['roles.view_any', 'roles.view', 'accounts.view_any']);

        $this->assertTrue(app(RoleManagementService::class)->canManageRole($actor, $role), 'precondition: role must be otherwise manageable');
        $this->assertFalse($actor->can('roles.update'));

        $this->actingAs($actor);
        $this->get("/admin/roles/{$role->id}/edit")->assertForbidden();
    }

    // ---- 2. same actor does not see the Edit action ----

    public function test_actor_satisfying_can_manage_role_but_missing_roles_update_does_not_see_edit_action(): void
    {
        $role = $this->roleWithPermissions('Manageable But No Update Ability Table', ['accounts.view_any']);
        $actor = $this->userWithPermissions(['roles.view_any', 'roles.view', 'accounts.view_any']);
        $this->actingAs($actor);

        Livewire::test(ListRoles::class)
            ->assertTableActionHidden('edit', $role);

        Livewire::test(ViewRole::class, ['record' => $role->getKey()])
            ->assertActionHidden('edit');

        $this->assertFalse(RoleResource::canEdit($role));
    }

    // ---- 3. canManageRole() satisfied but roles.delete missing -> Delete hidden and not invokable ----

    public function test_actor_satisfying_can_manage_role_but_missing_roles_delete_does_not_see_or_invoke_delete(): void
    {
        $role = $this->roleWithPermissions('Manageable But No Delete Ability', ['accounts.view_any']);
        $actor = $this->userWithPermissions(['roles.view_any', 'roles.view', 'roles.update', 'accounts.view_any']);

        $this->assertTrue(app(RoleManagementService::class)->canManageRole($actor, $role));
        $this->assertFalse($actor->can('roles.delete'));

        $this->actingAs($actor);

        $this->assertFalse(RoleResource::canDelete($role));

        Livewire::test(ListRoles::class)
            ->assertTableActionHidden('delete', $role);

        try {
            app(RoleManagementService::class)->deleteRole($actor, $role);
            $this->fail('Expected an AuthorizationException.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            // expected — deleteRole() itself independently requires roles.delete
        }

        $this->assertNotNull(Role::find($role->id));
    }

    // ---- 4. roles.update alone does not allow editing a role that fails canManageRole() ----

    public function test_roles_update_alone_does_not_allow_editing_a_role_that_fails_can_manage_role(): void
    {
        $role = $this->roleWithPermissions('Exceeds Actor Permissions For Update', ['accounts.view_any', 'accounts.delete']);
        $actor = $this->userWithPermissions(['roles.view_any', 'roles.view', 'roles.update']);

        $this->assertTrue($actor->can('roles.update'));
        $this->assertFalse(app(RoleManagementService::class)->canManageRole($actor, $role));

        $this->actingAs($actor);

        $this->assertFalse(RoleResource::canEdit($role));
        $this->get("/admin/roles/{$role->id}/edit")->assertForbidden();
    }

    // ---- 5. roles.delete alone does not allow deleting a role that fails canManageRole() ----

    public function test_roles_delete_alone_does_not_allow_deleting_a_role_that_fails_can_manage_role(): void
    {
        $role = $this->roleWithPermissions('Exceeds Actor Permissions For Delete', ['accounts.view_any', 'accounts.delete']);
        $actor = $this->userWithPermissions(['roles.view_any', 'roles.view', 'roles.delete']);

        $this->assertTrue($actor->can('roles.delete'));
        $this->assertFalse(app(RoleManagementService::class)->canManageRole($actor, $role));

        $this->actingAs($actor);

        $this->assertFalse(RoleResource::canDelete($role));

        Livewire::test(ListRoles::class)
            ->assertTableActionHidden('delete', $role);

        $this->expectException(ValidationException::class);
        app(RoleManagementService::class)->deleteRole($actor, $role);
    }

    // ---- 6. actor satisfying both the ability and canManageRole() can edit/delete an eligible custom role ----

    public function test_actor_satisfying_both_ability_and_can_manage_role_can_edit_and_delete(): void
    {
        $editableRole = $this->roleWithPermissions('Fully Eligible Editable Role', ['accounts.view_any']);
        $deletableRole = $this->roleWithPermissions('Fully Eligible Deletable Role', ['accounts.view_any']);
        $actor = $this->userWithPermissions(['roles.view_any', 'roles.view', 'roles.update', 'roles.delete', 'accounts.view_any']);

        $this->actingAs($actor);

        $this->assertTrue(RoleResource::canEdit($editableRole));
        $this->assertTrue(RoleResource::canDelete($deletableRole));

        $this->get("/admin/roles/{$editableRole->id}/edit")->assertOk();

        Livewire::test(ListRoles::class)
            ->assertTableActionVisible('edit', $editableRole)
            ->assertTableActionVisible('delete', $deletableRole)
            ->callTableAction('delete', $deletableRole);

        $this->assertNull(Role::find($deletableRole->id));
    }

    // ---- 7. Super Admin can edit/delete an eligible custom role but still cannot edit/delete a system role ----

    public function test_super_admin_can_manage_eligible_custom_role_but_not_a_system_role(): void
    {
        Artisan::call('oms:sync-permissions');

        $customRole = Role::create(['name' => 'Super Admin Manageable Custom Role', 'guard_name' => 'web']);
        $systemRole = Role::where('name', PermissionRegistry::ADMIN)->firstOrFail();

        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue(RoleResource::canEdit($customRole));
        $this->assertTrue(RoleResource::canDelete($customRole));
        $this->assertFalse(RoleResource::canEdit($systemRole));
        $this->assertFalse(RoleResource::canDelete($systemRole));

        $this->get("/admin/roles/{$customRole->id}/edit")->assertOk();
        $this->get("/admin/roles/{$systemRole->id}/edit")->assertForbidden();

        app(RoleManagementService::class)->deleteRole($superAdmin, $customRole);
        $this->assertNull(Role::find($customRole->id));
    }

    // ---- 8. rejected operations (ability present, canManageRole() fails) leave name/permissions/pivots unchanged ----

    public function test_rejected_operation_due_to_failed_can_manage_role_leaves_role_and_pivots_unchanged(): void
    {
        $role = $this->roleWithPermissions('Untouchable Despite Ability', ['accounts.view_any', 'accounts.delete']);
        $target = User::factory()->create();
        $target->assignRole($role);

        $actor = $this->userWithPermissions(['roles.view_any', 'roles.view', 'roles.update', 'roles.delete']);

        $originalName = $role->name;
        $originalPermissionNames = $role->permissions()->pluck('name')->sort()->values()->all();
        $originalUserIds = $role->users()->pluck('users.id')->sort()->values()->all();

        try {
            app(RoleManagementService::class)->updateRole($actor, $role, ['name' => 'Hijacked', 'permissions' => []]);
            $this->fail('Expected a ValidationException on update.');
        } catch (ValidationException) {
            // expected
        }

        try {
            app(RoleManagementService::class)->deleteRole($actor, $role);
            $this->fail('Expected a ValidationException on delete.');
        } catch (ValidationException) {
            // expected
        }

        $fresh = $role->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertSame($originalPermissionNames, $fresh->permissions()->pluck('name')->sort()->values()->all());
        $this->assertSame($originalUserIds, $fresh->users()->pluck('users.id')->sort()->values()->all());
    }

    private function permission(string $name): Permission
    {
        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
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

    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        foreach ($names as $name) {
            $user->givePermissionTo($this->permission($name));
        }

        return $user;
    }

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
