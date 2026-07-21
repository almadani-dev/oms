<?php

namespace Tests\Feature\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 4: genuine Livewire/Filament form and table-action tests for
 * RoleResource — grouped permission assignment, server-side revalidation of
 * crafted/out-of-scope permission submissions, no bulk actions, no
 * force-delete action, and that a newly created custom role is immediately
 * usable from UserResource's role selector.
 */
class RoleResourceLivewireTest extends TestCase
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

    // ---- creating a custom role through grouped permission checkboxes ----

    public function test_authorized_actor_creates_a_custom_role_with_grouped_permissions(): void
    {
        $this->permission('accounts.view_any');
        $this->permission('accounts.view');
        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.create', 'accounts.view_any', 'accounts.view']));

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Livewire Custom Role',
                'permissions' => [
                    'accounts' => ['accounts.view_any', 'accounts.view'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('name', 'Livewire Custom Role')->firstOrFail();
        $this->assertEqualsCanonicalizing(['accounts.view_any', 'accounts.view'], $role->permissions()->pluck('name')->all());
    }

    // ---- a non-Super-Admin cannot smuggle in a protected permission even via a crafted form fill ----

    public function test_crafted_form_submission_cannot_add_a_protected_permission(): void
    {
        $this->permission('accounts.view_any');
        $this->permission('roles.delete');
        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.create', 'accounts.view_any']));

        // 'roles.delete' is not in the actor's assignable-permission options,
        // so Filament's own CheckboxList "in" validation rejects it before
        // the request ever reaches RoleManagementService — the first of two
        // defense layers (see RoleManagementServiceTest for the second:
        // direct service-level revalidation).
        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Sneaky Role',
                'permissions' => [
                    'accounts' => ['accounts.view_any'],
                    'roles' => ['roles.delete'],
                ],
            ])
            ->call('create')
            ->assertHasErrors(['data.permissions.roles.0']);

        $this->assertNull(Role::where('name', 'Sneaky Role')->first());
    }

    // ---- an authorized update changes a custom role's permissions ----

    public function test_authorized_update_changes_permissions_through_the_form(): void
    {
        $this->permission('accounts.view_any');
        $this->permission('accounts.delete');
        $role = Role::create(['name' => 'Updatable Role', 'guard_name' => 'web']);
        $role->syncPermissions(['accounts.view_any']);

        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.update', 'accounts.view_any', 'accounts.delete']));

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm([
                'name' => 'Updatable Role',
                'permissions' => [
                    'accounts' => ['accounts.view_any', 'accounts.delete'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['accounts.view_any', 'accounts.delete'], $role->fresh()->permissions()->pluck('name')->all());
    }

    // ---- 36. no bulk-delete (or any bulk) action exists ----

    public function test_no_bulk_action_exists(): void
    {
        $this->actingAsSuperAdmin();

        $bulkActions = Livewire::test(ListRoles::class)->instance()->getTable()->getBulkActions();

        $this->assertCount(0, $bulkActions);
    }

    // ---- 37. no force-delete action exists ----

    public function test_no_force_delete_action_exists(): void
    {
        $role = Role::create(['name' => 'Any Role', 'guard_name' => 'web']);
        $this->actingAsSuperAdmin();

        Livewire::test(ListRoles::class)
            ->assertTableActionDoesNotExist('forceDelete', record: $role)
            ->assertTableBulkActionDoesNotExist('forceDelete');
    }

    // ---- an authorized delete removes an unused custom role via the table ----

    public function test_authorized_delete_removes_an_unused_custom_role(): void
    {
        $role = Role::create(['name' => 'Deletable Via Table', 'guard_name' => 'web']);
        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.delete']));

        Livewire::test(ListRoles::class)
            ->callTableAction('delete', $role);

        $this->assertNull(Role::find($role->id));
    }

    // ---- delete is hidden in the table for a role currently in use ----

    public function test_delete_action_is_hidden_for_a_role_in_use(): void
    {
        $role = Role::create(['name' => 'In Use Via Table', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($role);

        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.delete']));

        Livewire::test(ListRoles::class)
            ->assertTableActionHidden('delete', $role);
    }

    // ---- 40. UserResource's role selector can use a newly created custom role ----

    public function test_user_resource_role_selector_can_use_a_new_custom_role(): void
    {
        $role = Role::create(['name' => 'Assignable Custom Role', 'guard_name' => 'web']);
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.create', 'roles.view_any']));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Person',
                'email' => 'new-person-'.uniqid().'@example.com',
                'password' => 'Some-Password-123',
                'password_confirmation' => 'Some-Password-123',
                'roles' => ['Assignable Custom Role'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'like', 'new-person-%')->latest('id')->firstOrFail();
        $this->assertTrue($created->hasRole($role->name));
    }

    private function permission(string $name): Permission
    {
        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
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

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
