<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 4: proves RolePolicy is discoverable (explicitly registered, since
 * Spatie's Role model lives outside App\Models — see PolicyDiscoveryTest for
 * the equivalent proof style for ordinary models) and that each ability maps
 * to the documented permission + RoleManagementService target check. Also
 * demonstrates, directly, why the Policy alone cannot be relied on for
 * system-role protection: calling Gate for a real Super Admin bypasses the
 * Policy entirely (Gate::before), while calling the Policy object directly
 * (or RoleResource, tested elsewhere) does not.
 */
class RolePolicyTest extends TestCase
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

    public function test_role_model_resolves_to_the_explicitly_registered_role_policy(): void
    {
        $this->assertInstanceOf(RolePolicy::class, Gate::getPolicyFor(Role::class));
    }

    // ---- 1/2. roles.view_any gates the list ----

    public function test_user_without_view_any_is_denied(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->can('viewAny', Role::class));
    }

    public function test_user_with_view_any_is_allowed(): void
    {
        $user = $this->userWithPermission('roles.view_any');

        $this->assertTrue($user->can('viewAny', Role::class));
    }

    // ---- 3. roles.view does not grant create ----

    public function test_view_alone_does_not_grant_create(): void
    {
        $user = $this->userWithPermission('roles.view');

        $this->assertFalse($user->can('create', Role::class));
    }

    // ---- 4. roles.create required for create ----

    public function test_create_requires_roles_create(): void
    {
        $user = $this->userWithPermission('roles.create');

        $this->assertTrue($user->can('create', Role::class));
    }

    // ---- 5/6. roles.update / roles.delete required, plus target checks ----

    public function test_update_requires_roles_update_and_a_manageable_target(): void
    {
        $role = $this->customRole();

        $withoutAbility = $this->userWithPermission('roles.view_any');
        $this->assertFalse($withoutAbility->can('update', $role));

        $withAbility = $this->userWithPermission('roles.update');
        $this->assertTrue($withAbility->can('update', $role));
    }

    public function test_delete_requires_roles_delete_and_a_manageable_target(): void
    {
        $role = $this->customRole();

        $withoutAbility = $this->userWithPermission('roles.view_any');
        $this->assertFalse($withoutAbility->can('delete', $role));

        $withAbility = $this->userWithPermission('roles.delete');
        $this->assertTrue($withAbility->can('delete', $role));
    }

    // ---- system-role update/delete: the Policy itself correctly denies it ... ----

    public function test_policy_denies_update_of_a_system_role_for_a_non_super_admin(): void
    {
        Role::firstOrCreate(['name' => PermissionRegistry::ADMIN, 'guard_name' => 'web']);
        $systemRole = Role::where('name', PermissionRegistry::ADMIN)->firstOrFail();

        $user = $this->userWithPermission('roles.update');

        $this->assertFalse($user->can('update', $systemRole));
    }

    // ---- ... but Gate::before bypasses that denial for a real Super Admin, which is exactly why RoleResource/RoleManagementService must enforce this structurally outside the Gate (see SystemRoleProtectionTest) ----

    public function test_gate_before_bypasses_the_policy_denial_for_a_real_super_admin(): void
    {
        Role::firstOrCreate(['name' => PermissionRegistry::ADMIN, 'guard_name' => 'web']);
        $systemRole = Role::where('name', PermissionRegistry::ADMIN)->firstOrFail();

        $superAdmin = $this->superAdmin();

        // Direct Policy-object call: no Gate involved, correctly false.
        $this->assertFalse(app(RolePolicy::class)->update($superAdmin, $systemRole));

        // Through the Gate (`$user->can(...)`): Gate::before short-circuits
        // to true for a real Super Admin before RolePolicy::update() ever
        // runs. This is the documented reason RoleResource/
        // RoleManagementService cannot rely on Policy::update() alone.
        $this->assertTrue($superAdmin->can('update', $systemRole));
    }

    private function customRole(): Role
    {
        return Role::create(['name' => 'Custom Role '.uniqid(), 'guard_name' => 'web']);
    }

    private function userWithPermission(string $name): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);

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
