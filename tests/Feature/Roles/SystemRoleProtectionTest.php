<?php

namespace Tests\Feature\Roles;

use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Services\Roles\RoleManagementService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 4: proves the five system roles stay visible-but-protected regardless
 * of actor — including a real Super Admin, whom Gate::before would otherwise
 * let bypass RolePolicy entirely. RoleResource::canEdit()/canDelete() and
 * RoleManagementService::updateRole()/deleteRole() are what actually
 * structurally enforce this (see their docblocks); this test exercises that
 * enforcement end-to-end via genuine HTTP/Livewire and direct service calls.
 */
class SystemRoleProtectionTest extends TestCase
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

        Artisan::call('oms:sync-permissions');

        URL::forceRootUrl('http://localhost');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function systemRoleProvider(): array
    {
        return [
            'Super Admin' => [PermissionRegistry::SUPER_ADMIN],
            'Admin' => [PermissionRegistry::ADMIN],
            'Accountant' => [PermissionRegistry::ACCOUNTANT],
            'Project Manager' => [PermissionRegistry::PROJECT_MANAGER],
            'Viewer' => [PermissionRegistry::VIEWER],
        ];
    }

    // ---- 12. direct Edit URL for every system role is rejected, even for Super Admin ----

    #[DataProvider('systemRoleProvider')]
    public function test_direct_edit_url_for_every_system_role_is_rejected_for_super_admin(string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $this->actingAsSuperAdmin();

        $this->get("/admin/roles/{$role->id}/edit")->assertForbidden();
    }

    // ---- view is still allowed for every system role ----

    #[DataProvider('systemRoleProvider')]
    public function test_view_page_for_every_system_role_is_allowed_for_super_admin(string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $this->actingAsSuperAdmin();

        $this->get("/admin/roles/{$role->id}")->assertOk();
    }

    // ---- 10/11. Edit/Delete table actions are hidden for system roles ----

    public function test_edit_and_delete_table_actions_are_hidden_for_system_roles(): void
    {
        $role = Role::where('name', PermissionRegistry::ADMIN)->firstOrFail();
        $this->actingAsSuperAdmin();

        Livewire::test(ListRoles::class)
            ->assertTableActionHidden('edit', $role)
            ->assertTableActionHidden('delete', $role);
    }

    public function test_edit_header_action_is_hidden_for_a_system_role_on_its_view_page(): void
    {
        $role = Role::where('name', PermissionRegistry::VIEWER)->firstOrFail();
        $this->actingAsSuperAdmin();

        Livewire::test(\App\Filament\Resources\Roles\Pages\ViewRole::class, ['record' => $role->getKey()])
            ->assertActionHidden('edit');
    }

    // ---- 13. crafted update/delete of a system role is rejected even for Super Admin ----

    #[DataProvider('systemRoleProvider')]
    public function test_crafted_update_of_a_system_role_is_rejected_even_for_super_admin(string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $originalName = $role->name;
        $originalPermissionNames = $role->permissions()->pluck('name')->sort()->values()->all();
        $actor = $this->actingAsSuperAdmin();

        try {
            app(RoleManagementService::class)->updateRole($actor, $role, ['name' => 'Hijacked', 'permissions' => []]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // expected
        }

        // ---- 14. pivots/name remain unchanged after rejection ----
        $fresh = $role->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertSame($originalPermissionNames, $fresh->permissions()->pluck('name')->sort()->values()->all());
    }

    #[DataProvider('systemRoleProvider')]
    public function test_crafted_delete_of_a_system_role_is_rejected_even_for_super_admin(string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $actor = $this->actingAsSuperAdmin();

        $this->expectException(ValidationException::class);

        try {
            app(RoleManagementService::class)->deleteRole($actor, $role);
        } finally {
            $this->assertNotNull(Role::find($role->id));
        }
    }

    // ---- RoleResource::canEdit()/canDelete() structurally deny every system role for Super Admin ----

    public function test_resource_structurally_denies_edit_and_delete_for_every_system_role(): void
    {
        $this->actingAsSuperAdmin();

        foreach (self::systemRoleProvider() as [$roleName]) {
            $role = Role::where('name', $roleName)->firstOrFail();

            $this->assertFalse(RoleResource::canEdit($role), "{$roleName} should never be editable.");
            $this->assertFalse(RoleResource::canDelete($role), "{$roleName} should never be deletable.");
        }
    }

    private function actingAsSuperAdmin(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($user);

        return $user;
    }
}
