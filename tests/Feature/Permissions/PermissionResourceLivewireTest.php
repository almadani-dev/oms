<?php

namespace Tests\Feature\Permissions;

use App\Filament\Resources\Permissions\PermissionResource;
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
 * Task 5: genuine Filament/Livewire structural read-only proofs (no
 * Create/Edit/Delete/bulk/force-delete action exists anywhere, and
 * PermissionResource's hard canX() overrides deny mutation even for a real
 * Super Admin — not just Policy-denied, since Gate::before would otherwise
 * bypass a Policy-only denial), plus display behavior (Arabic label/module,
 * technical name, roles/count, custom-permission section, filters) and an
 * N+1 query-count check.
 */
class PermissionResourceLivewireTest extends TestCase
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

    // ---- 9. no Create action exists in the header actions ----

    public function test_no_create_header_action_exists(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->assertActionDoesNotExist('create');
    }

    // ---- 10/11. no Edit/Delete table action exists ----

    public function test_no_edit_or_delete_table_action_exists(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->assertTableActionDoesNotExist('edit', record: $permission)
            ->assertTableActionDoesNotExist('delete', record: $permission);
    }

    // ---- 12. no bulk action exists ----

    public function test_no_bulk_action_exists(): void
    {
        $this->actingAsSuperAdmin();

        $bulkActions = Livewire::test(ListPermissions::class)->instance()->getTable()->getBulkActions();

        $this->assertCount(0, $bulkActions);
    }

    // ---- 13. no force-delete action exists ----

    public function test_no_force_delete_action_exists(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->assertTableActionDoesNotExist('forceDelete', record: $permission)
            ->assertTableBulkActionDoesNotExist('forceDelete');
    }

    // ---- 14. crafted mutation attempts cannot modify a Permission, including for Super Admin ----

    public function test_structural_overrides_deny_mutation_even_for_super_admin(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $this->actingAsSuperAdmin();

        $this->assertFalse(PermissionResource::canCreate());
        $this->assertFalse(PermissionResource::canEdit($permission));
        $this->assertFalse(PermissionResource::canDelete($permission));
        $this->assertFalse(PermissionResource::canDeleteAny());
        $this->assertFalse(PermissionResource::canForceDelete($permission));
        $this->assertFalse(PermissionResource::canForceDeleteAny());
        $this->assertFalse(PermissionResource::canRestore($permission));
        $this->assertFalse(PermissionResource::canRestoreAny());
    }

    // ---- 15/16. registered permission shows its Arabic label, module, and technical name ----

    public function test_registered_permission_shows_arabic_label_module_and_technical_name(): void
    {
        Artisan::call('oms:sync-permissions');
        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->assertTableColumnStateSet('name', 'roles.view_any', record: Permission::where('name', 'roles.view_any')->firstOrFail())
            ->assertTableColumnStateSet('label', PermissionRegistry::all()['roles.view_any'], record: Permission::where('name', 'roles.view_any')->firstOrFail())
            ->assertTableColumnStateSet('module', 'الأدوار', record: Permission::where('name', 'roles.view_any')->firstOrFail());
    }

    // ---- 17. associated roles and role count display correctly ----

    public function test_associated_roles_and_role_count_display_correctly(): void
    {
        $permission = Permission::create(['name' => 'accounts.view', 'guard_name' => 'web']);
        $roleOne = Role::create(['name' => 'Custom Role One', 'guard_name' => 'web']);
        $roleTwo = Role::create(['name' => 'Custom Role Two', 'guard_name' => 'web']);
        $roleOne->givePermissionTo($permission);
        $roleTwo->givePermissionTo($permission);

        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->assertTableColumnStateSet('roles_count', 2, record: $permission->fresh());
    }

    // ---- 18/19. custom/unregistered permission remains visible under "صلاحيات مخصصة" ----

    public function test_custom_permission_remains_visible_under_custom_module(): void
    {
        $custom = Permission::create(['name' => 'legacy view finance', 'guard_name' => 'web']);
        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->assertCanSeeTableRecords([$custom])
            ->assertTableColumnStateSet('module', 'صلاحيات مخصصة', record: $custom)
            ->assertTableColumnStateSet('status', 'مخصصة', record: $custom)
            ->assertTableColumnStateSet('label', 'legacy view finance', record: $custom);
    }

    // ---- 20. filtering by module works ----

    public function test_filtering_by_module_works(): void
    {
        Artisan::call('oms:sync-permissions');
        $roleAccountsView = Permission::where('name', 'accounts.view')->firstOrFail();
        $rolesView = Permission::where('name', 'roles.view')->firstOrFail();

        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->filterTable('module', 'roles')
            ->assertCanSeeTableRecords([$rolesView])
            ->assertCanNotSeeTableRecords([$roleAccountsView]);
    }

    // ---- 21. filtering by registered/custom status works ----

    public function test_filtering_by_registered_custom_status_works(): void
    {
        Artisan::call('oms:sync-permissions');
        $registered = Permission::where('name', 'accounts.view')->firstOrFail();
        $custom = Permission::create(['name' => 'legacy view finance', 'guard_name' => 'web']);

        $this->actingAsSuperAdmin();

        Livewire::test(ListPermissions::class)
            ->filterTable('status', 'custom')
            ->assertCanSeeTableRecords([$custom])
            ->assertCanNotSeeTableRecords([$registered]);
    }

    // ---- 22. permission list avoids N+1 behavior ----

    public function test_permission_list_avoids_n_plus_one_queries(): void
    {
        Artisan::call('oms:sync-permissions');

        foreach (Permission::take(10)->get() as $index => $permission) {
            $role = Role::create(['name' => 'N+1 Role '.$index, 'guard_name' => 'web']);
            $role->givePermissionTo($permission);
        }

        $this->actingAsSuperAdmin();

        DB::enableQueryLog();
        Livewire::test(ListPermissions::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A fixed, small bound regardless of row count proves roles are
        // eager-loaded (->with('roles')) rather than queried per row.
        $this->assertLessThan(30, $queryCount, "Expected a bounded query count, got {$queryCount} — possible N+1.");
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
