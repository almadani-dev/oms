<?php

namespace Tests\Feature\Permissions;

use App\Filament\Resources\Permissions\PermissionResource;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5: real HTTP tests for PermissionResource's index/view routes,
 * matching the style of RoleResourceHttpTest/UserResourceAuthorizationTest.
 * Also proves no Create/Edit route exists at all (404, not 403 — the pages
 * were simply never registered in PermissionResource::getPages()).
 */
class PermissionResourceHttpTest extends TestCase
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

    // ---- 1/2. permissions.view_any gates the index ----

    public function test_user_without_view_any_gets_403_on_index(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/permissions')->assertForbidden();
    }

    public function test_user_with_view_any_can_access_the_index(): void
    {
        $this->actingAs($this->userWithPermission('permissions.view_any'));

        $this->get('/admin/permissions')->assertOk();
    }

    // ---- 3. permissions.view is required for the View page ----

    public function test_view_permission_is_required_for_the_view_page(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);

        $this->actingAs($this->userWithPermission('permissions.view_any'));
        $this->get("/admin/permissions/{$permission->id}")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['permissions.view_any', 'permissions.view']));
        $this->get("/admin/permissions/{$permission->id}")->assertOk();
    }

    // ---- 4. a permission for another module does not grant access ----

    public function test_permission_for_another_module_does_not_grant_index_access(): void
    {
        $this->actingAs($this->userWithPermission('accounts.view_any'));

        $this->get('/admin/permissions')->assertForbidden();
    }

    // ---- 5. navigation visibility follows permissions.view_any ----

    public function test_navigation_visibility_matches_view_any_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(PermissionResource::canViewAny());

        $this->actingAs($this->userWithPermission('permissions.view_any'));
        $this->assertTrue(PermissionResource::canViewAny());
    }

    // ---- 6. Super Admin can access list and view ----

    public function test_super_admin_can_access_list_and_view(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $this->actingAsSuperAdmin();

        $this->get('/admin/permissions')->assertOk();
        $this->get("/admin/permissions/{$permission->id}")->assertOk();
    }

    // ---- 7. no Create/Edit route exists — the pages were never registered ----

    public function test_no_create_route_exists(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/admin/permissions/create')->assertNotFound();
    }

    public function test_no_edit_route_exists(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $this->actingAsSuperAdmin();

        $this->get("/admin/permissions/{$permission->id}/edit")->assertNotFound();
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
