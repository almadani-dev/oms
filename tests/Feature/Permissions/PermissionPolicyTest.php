<?php

namespace Tests\Feature\Permissions;

use App\Models\User;
use App\Policies\PermissionPolicy;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5: proves PermissionPolicy is discoverable (explicitly registered,
 * since Spatie's Permission model lives outside App\Models — mirrors
 * RolePolicyTest's proof style), that viewAny/view map to
 * permissions.view_any/permissions.view, that every mutation ability is
 * unconditionally false, and — the important part — that Gate::before still
 * bypasses that denial for a real Super Admin through the Gate, which is
 * exactly why PermissionResource must hard-override its canX() methods
 * rather than relying on the Policy alone.
 */
class PermissionPolicyTest extends TestCase
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

    public function test_permission_model_resolves_to_the_explicitly_registered_permission_policy(): void
    {
        $this->assertInstanceOf(PermissionPolicy::class, Gate::getPolicyFor(Permission::class));
    }

    // ---- 1/2. permissions.view_any gates viewAny ----

    public function test_user_without_view_any_is_denied(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->can('viewAny', Permission::class));
    }

    public function test_user_with_view_any_is_allowed(): void
    {
        $user = $this->userWithPermission('permissions.view_any');

        $this->assertTrue($user->can('viewAny', Permission::class));
    }

    // ---- 3. permissions.view gates view ----

    public function test_view_requires_permissions_view(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);

        $withoutAbility = $this->userWithPermission('permissions.view_any');
        $this->assertFalse($withoutAbility->can('view', $permission));

        $withAbility = $this->userWithPermission('permissions.view');
        $this->assertTrue($withAbility->can('view', $permission));
    }

    // ---- 4. a permission for another module does not grant access ----

    public function test_permission_for_another_module_does_not_grant_access(): void
    {
        $user = $this->userWithPermission('accounts.view_any');

        $this->assertFalse($user->can('viewAny', Permission::class));
    }

    // ---- every mutation ability is unconditionally false ----

    public function test_every_mutation_ability_is_denied_for_a_fully_permissioned_user(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $user = $this->userWithPermissions(['permissions.view_any', 'permissions.view', 'permissions.sync']);

        $this->assertFalse($user->can('create', Permission::class));
        $this->assertFalse($user->can('update', $permission));
        $this->assertFalse($user->can('delete', $permission));
        $this->assertFalse($user->can('deleteAny', Permission::class));
        $this->assertFalse($user->can('restore', $permission));
        $this->assertFalse($user->can('restoreAny', Permission::class));
        $this->assertFalse($user->can('forceDelete', $permission));
        $this->assertFalse($user->can('forceDeleteAny', Permission::class));
    }

    // ---- the Policy itself correctly denies mutation for a real Super Admin ... ----

    public function test_policy_denies_mutation_for_a_real_super_admin_when_called_directly(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $superAdmin = $this->superAdmin();

        $policy = app(PermissionPolicy::class);

        $this->assertFalse($policy->create($superAdmin));
        $this->assertFalse($policy->update($superAdmin, $permission));
        $this->assertFalse($policy->delete($superAdmin, $permission));
        $this->assertFalse($policy->deleteAny($superAdmin));
        $this->assertFalse($policy->forceDelete($superAdmin, $permission));
        $this->assertFalse($policy->forceDeleteAny($superAdmin));
    }

    // ---- ... but Gate::before bypasses that denial through the Gate, which is why PermissionResource hard-overrides canX() structurally instead of relying on the Policy ----

    public function test_gate_before_bypasses_the_policy_denial_for_a_real_super_admin(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $superAdmin = $this->superAdmin();

        $this->assertFalse(app(PermissionPolicy::class)->update($superAdmin, $permission));
        $this->assertTrue($superAdmin->can('update', $permission));
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

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        return $user;
    }
}
