<?php

namespace Tests\Feature\Roles;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 4: real HTTP tests for RoleResource's index/create/view/edit routes,
 * matching the style of UserResourceAuthorizationTest/
 * ResourceHttpAuthorizationTest.
 */
class RoleResourceHttpTest extends TestCase
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

    // ---- 1/2. roles.view_any gates the index ----

    public function test_user_without_view_any_gets_403_on_index(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/roles')->assertForbidden();
    }

    public function test_user_with_view_any_can_access_the_index(): void
    {
        $this->actingAs($this->userWithPermission('roles.view_any'));

        $this->get('/admin/roles')->assertOk();
    }

    // ---- 3. roles.view does not grant create ----

    public function test_view_alone_does_not_grant_create(): void
    {
        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.view']));

        $this->get('/admin/roles/create')->assertForbidden();
    }

    // ---- 4. roles.create is required for the Create page ----

    public function test_create_permission_is_required_for_create_page(): void
    {
        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.create']));

        $this->get('/admin/roles/create')->assertOk();
    }

    // ---- 5. roles.update is required for Edit ----

    public function test_update_permission_is_required_for_edit(): void
    {
        $role = Role::create(['name' => 'Editable Role', 'guard_name' => 'web']);

        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.view']));
        $this->get("/admin/roles/{$role->id}/edit")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['roles.view_any', 'roles.view', 'roles.update']));
        $this->get("/admin/roles/{$role->id}/edit")->assertOk();
    }

    // ---- 6. roles.delete is required for delete ----

    public function test_delete_permission_is_required_for_delete(): void
    {
        $role = Role::create(['name' => 'Deletable Role', 'guard_name' => 'web']);

        $withoutDelete = $this->userWithPermissions(['roles.view_any', 'roles.update']);
        $this->actingAs($withoutDelete);
        $this->assertFalse($withoutDelete->can('delete', $role));

        $withDelete = $this->userWithPermissions(['roles.view_any', 'roles.update', 'roles.delete']);
        $this->actingAs($withDelete);
        $this->assertTrue($withDelete->can('delete', $role));
    }

    // ---- 7. navigation visibility matches roles.view_any ----

    public function test_navigation_visibility_matches_view_any_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(RoleResource::canViewAny());

        $this->actingAs($this->userWithPermission('roles.view_any'));
        $this->assertTrue(RoleResource::canViewAny());
    }

    // ---- 8. Super Admin can access list/create/view ----

    public function test_super_admin_can_access_list_create_and_view(): void
    {
        $role = Role::create(['name' => 'Some Custom Role', 'guard_name' => 'web']);
        $this->actingAsSuperAdmin();

        $this->get('/admin/roles')->assertOk();
        $this->get('/admin/roles/create')->assertOk();
        $this->get("/admin/roles/{$role->id}")->assertOk();
        $this->get("/admin/roles/{$role->id}/edit")->assertOk();
    }

    private function userWithPermission(string $name): User
    {
        return $this->userWithPermissions([$name]);
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
