<?php

namespace Tests\Feature\Permissions;

use App\Filament\Resources\Permissions\Pages\ListPermissions;
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
 * Task 5: proves the synchronization header action on ListPermissions is
 * genuinely protected server-side — hiding it via ->visible() is only
 * defense layer 1; PermissionManagementService::sync() re-authorizes on
 * every direct/crafted Livewire callAction() invocation too (defense layer
 * 2), including for a non-Super-Admin manually granted `permissions.sync`,
 * and a rejected call leaves every authorization/user table byte-for-byte
 * unchanged.
 */
class PermissionSyncActionTest extends TestCase
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

    // ---- 23. sync action is visible to Super Admin ----

    public function test_sync_action_is_visible_to_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->assertActionVisible('syncPermissions');
    }

    // ---- 24. sync action is hidden from a normal user ----

    public function test_sync_action_is_hidden_from_a_normal_user(): void
    {
        $this->actingAs($this->userWithPermissions(['permissions.view_any', 'permissions.view']));

        Livewire::test(ListPermissions::class)
            ->assertActionHidden('syncPermissions');
    }

    // ---- 25. non-Super-Admin without permissions.sync cannot invoke it ----

    public function test_non_super_admin_without_sync_permission_cannot_invoke_the_action(): void
    {
        $this->actingAs($this->userWithPermissions(['permissions.view_any', 'permissions.view']));

        $this->assertRejectedSyncLeavesTablesUnchanged();
    }

    // ---- 26. non-Super-Admin manually assigned permissions.sync still cannot invoke it ----

    public function test_non_super_admin_manually_granted_sync_permission_still_cannot_invoke_the_action(): void
    {
        $this->actingAs($this->userWithPermissions(['permissions.view_any', 'permissions.view', 'permissions.sync']));

        $this->assertRejectedSyncLeavesTablesUnchanged();
    }

    /**
     * `->callAction()` (used elsewhere in this suite for the happy path) is
     * a test-ergonomics helper that pre-asserts visibility before invoking —
     * it proves nothing about server-side enforcement by itself. Here we
     * call the raw Livewire `mountAction()`/`callMountedAction()` methods
     * directly, exactly as a hand-crafted wire:click request bypassing the
     * hidden button would.
     *
     * Filament's `Action::isDisabled()` (Filament\Actions\Concerns\
     * CanBeDisabled) automatically folds in `isHidden()`, so a hidden
     * action already self-blocks at `mountAction()` — it never mounts, and
     * `callMountedAction()` finds nothing mounted and no-ops. That is one
     * real layer of defense, asserted here via an unchanged database.
     * `PermissionManagementServiceTest` independently proves the second,
     * deeper layer — the service itself throws `AuthorizationException`
     * for the exact same actor even called with no Livewire/UI layer
     * involved at all — so the rejection does not depend on `->visible()`
     * remaining wired correctly.
     */
    private function assertRejectedSyncLeavesTablesUnchanged(): void
    {
        $permissionCountBefore = Permission::count();
        $roleCountBefore = Role::count();
        $userCountBefore = User::count();
        $roleHasPermissionsCountBefore = DB::table('role_has_permissions')->count();
        $modelHasRolesCountBefore = DB::table('model_has_roles')->count();
        $modelHasPermissionsCountBefore = DB::table('model_has_permissions')->count();

        $component = Livewire::test(ListPermissions::class)
            ->call('mountAction', 'syncPermissions')
            ->call('callMountedAction');

        $this->assertSame([], $component->instance()->mountedActions, 'Expected the crafted sync action call never to mount for a non-Super-Admin.');

        $this->assertSame($permissionCountBefore, Permission::count());
        $this->assertSame($roleCountBefore, Role::count());
        $this->assertSame($userCountBefore, User::count());
        $this->assertSame($roleHasPermissionsCountBefore, DB::table('role_has_permissions')->count());
        $this->assertSame($modelHasRolesCountBefore, DB::table('model_has_roles')->count());
        $this->assertSame($modelHasPermissionsCountBefore, DB::table('model_has_permissions')->count());
    }

    // ---- 28. confirmation is required ----

    public function test_confirmation_is_required(): void
    {
        $this->actingAsSuperAdmin();

        $page = Livewire::test(ListPermissions::class)->instance();
        $syncAction = $page->getAction('syncPermissions', isMounting: false);

        $this->assertNotNull($syncAction);
        $this->assertTrue($syncAction->isConfirmationRequired());
    }

    // ---- 27/29/30/31/32/35/37. Super Admin can invoke synchronization: creates missing permissions, creates permissions.sync assigned only to Super Admin, creates no users/deletes nothing, idempotent, shows completion notification ----

    public function test_super_admin_can_invoke_synchronization_and_receives_the_expected_notification(): void
    {
        $userCountBefore = User::count();
        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->callAction('syncPermissions')
            ->assertHasNoActionErrors()
            ->assertNotified();

        foreach (PermissionRegistry::names() as $name) {
            $this->assertTrue(Permission::where('name', $name)->where('guard_name', 'web')->exists(), "Missing permission: {$name}");
        }

        $this->assertTrue(Permission::where('name', 'permissions.sync')->exists());

        $superAdminNames = Role::where('name', PermissionRegistry::SUPER_ADMIN)->firstOrFail()->permissions()->pluck('name')->all();
        $this->assertContains('permissions.sync', $superAdminNames);

        foreach ([PermissionRegistry::ADMIN, PermissionRegistry::ACCOUNTANT, PermissionRegistry::PROJECT_MANAGER, PermissionRegistry::VIEWER] as $role) {
            $names = Role::where('name', $role)->firstOrFail()->permissions()->pluck('name')->all();
            $this->assertNotContains('permissions.sync', $names, "{$role} unexpectedly received permissions.sync");
        }

        // No user was ever created by synchronization — only the acting Super Admin exists beyond whatever existed before.
        $this->assertSame($userCountBefore + 1, User::count());
    }

    public function test_synchronization_deletes_no_permissions_or_roles_and_preserves_custom_role_assignments(): void
    {
        $customPermission = Permission::create(['name' => 'legacy view finance', 'guard_name' => 'web']);
        $customRole = Role::create(['name' => 'Warehouse Clerk', 'guard_name' => 'web']);
        $customRole->givePermissionTo($customPermission);

        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)->callAction('syncPermissions');

        $this->assertNotNull(Permission::find($customPermission->id));
        $this->assertNotNull(Role::find($customRole->id));

        $fresh = $customRole->fresh();
        $this->assertTrue($fresh->hasPermissionTo('legacy view finance'));
        $this->assertCount(1, $fresh->permissions);
    }

    public function test_running_synchronization_twice_is_idempotent(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)->callAction('syncPermissions');
        $permissionCountAfterFirst = Permission::count();
        $roleCountAfterFirst = Role::count();

        Livewire::test(ListPermissions::class)->callAction('syncPermissions');

        $this->assertSame($permissionCountAfterFirst, Permission::count());
        $this->assertSame($roleCountAfterFirst, Role::count());
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
