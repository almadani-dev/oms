<?php

namespace Tests\Feature\Audit\Security;

use App\Models\User;
use App\Services\Audit\Exceptions\AuditPersistenceException;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * OMS Task 9B.4 — the strict atomicity guarantee for security mutations.
 *
 * A REQUIRED audit insert that cannot be persisted must take the whole
 * security mutation down with it: no user row, no activation change, no
 * password, no `model_has_roles` pivot and no `role_has_permissions` pivot may
 * ever remain committed without its AuditEvent. The failure is forced the
 * bluntest honest way — the `audit_events` table is dropped, so the insert
 * AuditLogger performs raises a real driver error, which
 * AuditFailureMode::Required turns into an AuditPersistenceException inside
 * the service's own transaction.
 */
class SecurityAuditAtomicityTest extends SecurityAuditTestCase
{
    private function breakAuditStorage(): void
    {
        Schema::drop('audit_events');
    }

    // ---- users -----------------------------------------------------------

    public function test_a_failed_required_audit_rolls_back_a_user_creation(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Viewer');

        $userCountBefore = User::withTrashed()->count();
        $pivotCountBefore = DB::table('model_has_roles')->count();

        $this->breakAuditStorage();

        try {
            $this->users()->createUser($actor, [
                'name' => 'يجب ألا يبقى',
                'email' => 'rollback@example.test',
                'password' => 'password-value',
                'roles' => ['Viewer'],
            ]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame($userCountBefore, User::withTrashed()->count(), 'The user creation must have rolled back.');
        $this->assertSame($pivotCountBefore, DB::table('model_has_roles')->count(), 'The role pivots must have rolled back.');
        $this->assertSame(0, DB::transactionLevel(), 'No transaction may be left open.');
    }

    public function test_a_failed_required_audit_rolls_back_a_user_update_including_its_role_pivots(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Admin');
        $this->role('Viewer');

        $target = User::factory()->create(['name' => 'الاسم الأصلي', 'is_active' => true]);
        $target->syncRoles(['Viewer']);
        $originalHash = $target->password;

        $this->breakAuditStorage();

        try {
            $this->users()->updateUser($actor, $target, [
                'name' => 'الاسم المعدل',
                'is_active' => false,
                'password' => 'a-new-password',
                'roles' => ['Admin'],
            ]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $fresh = User::findOrFail($target->id);

        $this->assertSame('الاسم الأصلي', $fresh->name, 'The rename must have rolled back.');
        $this->assertTrue((bool) $fresh->is_active, 'The deactivation must have rolled back.');
        $this->assertSame($originalHash, $fresh->password, 'The password change must have rolled back.');
        $this->assertSame(['Viewer'], $fresh->roles()->pluck('name')->all(), 'The role replacement must have rolled back.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_required_audit_rolls_back_a_user_deletion(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $target = User::factory()->create();

        $this->breakAuditStorage();

        try {
            $this->users()->deleteUser($actor, $target);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertNull(User::withTrashed()->findOrFail($target->id)->deleted_at, 'The soft delete must have rolled back.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_required_audit_rolls_back_a_user_restore(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $target = User::factory()->create();
        $target->delete();

        $this->breakAuditStorage();

        try {
            $this->users()->restoreUser($actor, User::withTrashed()->findOrFail($target->id));
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertNotNull(User::withTrashed()->findOrFail($target->id)->deleted_at, 'The restore must have rolled back.');
    }

    // ---- roles -----------------------------------------------------------

    public function test_a_failed_required_audit_rolls_back_a_role_creation(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->permissions(['projects.view']);

        $roleCountBefore = Role::count();
        $pivotCountBefore = DB::table('role_has_permissions')->count();

        $this->breakAuditStorage();

        try {
            $this->roles()->createRole($actor, ['name' => 'دور مؤقت', 'permissions' => ['projects.view']]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame($roleCountBefore, Role::count(), 'The role creation must have rolled back.');
        $this->assertSame($pivotCountBefore, DB::table('role_has_permissions')->count(), 'The permission pivots must have rolled back.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_required_audit_rolls_back_a_role_permission_replacement(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->permissions(['projects.view', 'accounts.view']);

        $role = $this->role('دور قابل للتعديل', ['projects.view']);

        $this->breakAuditStorage();

        try {
            $this->roles()->updateRole($actor, $role, [
                'name' => 'اسم جديد',
                'permissions' => ['accounts.view'],
            ]);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $fresh = Role::findOrFail($role->id);

        $this->assertSame('دور قابل للتعديل', $fresh->name, 'The rename must have rolled back.');
        $this->assertSame(['projects.view'], $fresh->permissions()->pluck('name')->all(), 'The permission replacement must have rolled back.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_failed_required_audit_rolls_back_a_role_deletion(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $role = $this->role('دور باقٍ');

        $this->breakAuditStorage();

        try {
            $this->roles()->deleteRole($actor, $role);
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertNotNull(Role::find($role->id), 'The role deletion must have rolled back.');
    }

    // ---- permission synchronisation --------------------------------------

    public function test_a_failed_required_audit_rolls_back_the_whole_permission_sync(): void
    {
        $permissionCountBefore = Permission::count();
        $roleCountBefore = Role::count();

        $this->breakAuditStorage();

        try {
            app(\App\Services\Permissions\PermissionSyncService::class)->sync();
            $this->fail('Expected AuditPersistenceException.');
        } catch (AuditPersistenceException) {
            // expected
        }

        $this->assertSame($permissionCountBefore, Permission::count(), 'Every created permission must have rolled back.');
        $this->assertSame($roleCountBefore, Role::count(), 'Every created system role must have rolled back.');
        $this->assertSame(0, DB::transactionLevel());
    }

    // ---- the mirror-image guarantee --------------------------------------

    /**
     * The mirror-image guarantee: an AuditEvent never survives a business
     * transaction its caller rolls back.
     */
    public function test_the_audit_event_rolls_back_with_a_surrounding_business_transaction(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $userCountBefore = User::withTrashed()->count();

        try {
            DB::transaction(function () use ($actor): void {
                $this->users()->createUser($actor, [
                    'name' => 'داخل معاملة',
                    'email' => 'inside@example.test',
                    'password' => 'password-value',
                ]);

                $this->assertCount(1, $this->securityEvents('user'), 'The event exists inside the open transaction.');

                throw new \RuntimeException('business failure after the audited write');
            });
            $this->fail('Expected the business exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('business failure after the audited write', $e->getMessage());
        }

        $this->assertCount(0, $this->securityEvents('user'), 'The audit row must roll back with its business transaction.');
        $this->assertSame($userCountBefore, User::withTrashed()->count());
    }

    /**
     * The last-active-Super-Admin guard runs inside the same transaction and
     * AFTER the point a naive implementation might have written its event —
     * a rejected action must leave no audit trace at all.
     */
    public function test_a_rejected_last_active_super_admin_deactivation_writes_no_event(): void
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        // An INACTIVE Super Admin actor: still privileged enough to pass
        // canManageUser(), but not counted as a surviving active Super Admin
        // — which is exactly what makes $lastAdmin the last one.
        $actor = User::factory()->create(['is_active' => false]);
        $actor->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->actingAs($actor);

        $lastAdmin = User::factory()->create(['is_active' => true]);
        $lastAdmin->assignRole(PermissionRegistry::SUPER_ADMIN);

        try {
            $this->users()->updateUser($actor, $lastAdmin, ['is_active' => false, 'roles' => []]);
            $this->fail('Expected the deactivation of the last active Super Admin to be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            // expected
        }

        $this->assertCount(0, $this->securityEvents('user'));
        $this->assertTrue((bool) $lastAdmin->fresh()->is_active);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_a_successful_security_write_leaves_no_transaction_open(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $this->assertSame(0, DB::transactionLevel());

        $this->users()->createUser($actor, [
            'name' => 'ناجح',
            'email' => 'ok@example.test',
            'password' => 'password-value',
        ]);

        $this->assertSame(0, DB::transactionLevel());
        $this->assertCount(1, $this->securityEvents('user'));
    }
}
