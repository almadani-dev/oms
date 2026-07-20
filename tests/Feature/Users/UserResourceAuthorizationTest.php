<?php

namespace Tests\Feature\Users;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 3 replacement for the Task-1 `UserResourceLockdownTest` (deleted):
 * that test's entire premise — "non-Super-Admin always 403s" — is exactly
 * what granular `users.*` permissions replace. Real HTTP requests against
 * the actual panel routes, same schema-only SQLite + URL::forceRootUrl
 * approach as `ResourceHttpAuthorizationTest`.
 */
class UserResourceAuthorizationTest extends TestCase
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

    // ---- 5/6. users.view_any gates the list ----

    public function test_user_without_view_any_gets_403_on_index(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/users')->assertForbidden();
    }

    public function test_user_with_view_any_can_access_the_index(): void
    {
        $this->actingAs($this->userWithPermission('users.view_any'));

        $this->get('/admin/users')->assertOk();
    }

    // ---- 7. users.view does not grant create ----

    public function test_view_alone_does_not_grant_create(): void
    {
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.view']));

        $this->get('/admin/users/create')->assertForbidden();
    }

    // ---- 8. users.create allows creation but not update/delete ----

    public function test_create_permission_allows_create_page_but_not_edit(): void
    {
        $target = User::factory()->create();
        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.create']));

        $this->get('/admin/users/create')->assertOk();
        $this->get("/admin/users/{$target->id}/edit")->assertForbidden();
    }

    // ---- 9. users.update is required for edit ----

    public function test_update_permission_is_required_for_edit(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.view']));
        $this->get("/admin/users/{$target->id}/edit")->assertForbidden();

        $this->actingAs($this->userWithPermissions(['users.view_any', 'users.view', 'users.update']));
        $this->get("/admin/users/{$target->id}/edit")->assertOk();
    }

    // ---- 10. users.delete is required for delete ----

    public function test_delete_permission_is_required_for_delete(): void
    {
        $target = User::factory()->create();

        $withoutDelete = $this->userWithPermissions(['users.view_any', 'users.view', 'users.update']);
        $this->assertFalse($withoutDelete->can('delete', $target));

        $withDelete = $this->userWithPermissions(['users.view_any', 'users.view', 'users.update', 'users.delete']);
        $this->assertTrue($withDelete->can('delete', $target));
    }

    // ---- 11. users.restore is required for restore ----

    public function test_restore_permission_is_required_for_restore(): void
    {
        $target = User::factory()->create();
        $target->delete();

        $withoutRestore = $this->userWithPermissions(['users.view_any']);
        $this->assertFalse($withoutRestore->can('restore', $target));

        $withRestore = $this->userWithPermissions(['users.view_any', 'users.restore']);
        $this->assertTrue($withRestore->can('restore', $target));
    }

    // ---- 12. navigation visibility matches users.view_any ----

    public function test_navigation_visibility_matches_view_any_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(UserResource::canViewAny());

        $this->actingAs($this->userWithPermission('users.view_any'));
        $this->assertTrue(UserResource::canViewAny());
    }

    // ---- Super Admin still reaches every route (Gate::before bypass unaffected) ----

    public function test_super_admin_can_access_every_user_resource_route(): void
    {
        $target = User::factory()->create();
        $this->actingAsSuperAdmin();

        $this->get('/admin/users')->assertOk();
        $this->get('/admin/users/create')->assertOk();
        $this->get("/admin/users/{$target->id}")->assertOk();
        $this->get("/admin/users/{$target->id}/edit")->assertOk();
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
