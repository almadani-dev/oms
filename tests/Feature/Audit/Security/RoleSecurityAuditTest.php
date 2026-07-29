<?php

namespace Tests\Feature\Audit\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * OMS Task 9B.4 — the audited Role write paths (RoleManagementService, the
 * only place RoleResource's pages and table actions mutate a role or its
 * permissions).
 */
class RoleSecurityAuditTest extends SecurityAuditTestCase
{
    /**
     * A Super Admin actor passes the service's ->can('roles.*') checks via
     * Gate::before regardless, but the ability rows have to exist so that
     * RoleManagementService::assignablePermissionNames() has a realistic
     * permission table to draw from.
     */
    private function grantRoleManagement(): void
    {
        $this->permissions(['roles.create', 'roles.update', 'roles.delete']);
    }

    // ---- create ---------------------------------------------------------

    public function test_creating_a_role_writes_one_security_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();
        $this->permissions(['projects.view', 'projects.view_any']);

        $role = $this->roles()->createRole($actor, [
            'name' => 'أمين مستودع',
            'permissions' => ['projects.view_any', 'projects.view'],
        ]);

        $event = $this->onlySecurityEvent('role', 'created');

        $this->assertSame((string) $role->id, $event->subject_key);
        $this->assertSame('أمين مستودع', $event->subject_label);
        $this->assertNull($event->old_values);
        $this->assertSame([
            'role_id' => $role->id,
            'name' => 'أمين مستودع',
            'permissions' => ['projects.view', 'projects.view_any'],
        ], $event->new_values);
    }

    /**
     * Two permissions means two `role_has_permissions` rows — and still
     * exactly one audit event. Spatie's pivot writes have no independent
     * audit path (neither Role nor Permission is registered in the
     * general-CRUD AuditSubjectRegistry), so this is structural.
     */
    public function test_a_multi_permission_role_creation_writes_no_per_pivot_events(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();
        $this->permissions(['projects.view', 'projects.view_any', 'accounts.view']);

        $role = $this->roles()->createRole($actor, [
            'name' => 'دور متعدد',
            'permissions' => ['projects.view', 'projects.view_any', 'accounts.view'],
        ]);

        $this->assertSame(3, DB::table('role_has_permissions')->where('role_id', $role->id)->count());
        $this->assertCount(1, $this->securityEvents('role'));
    }

    // ---- update ---------------------------------------------------------

    public function test_replacing_a_roles_permissions_writes_one_event_with_a_full_diff(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();
        $this->permissions(['projects.view', 'projects.view_any', 'accounts.view', 'accounts.view_any']);

        $role = $this->role('دور مخصص', ['projects.view', 'projects.view_any']);

        $this->roles()->updateRole($actor, $role, [
            'name' => 'دور مخصص',
            'permissions' => ['accounts.view_any', 'accounts.view', 'projects.view'],
        ]);

        $event = $this->onlySecurityEvent('role', 'updated');

        $this->assertSame([
            'permissions' => ['projects.view', 'projects.view_any'],
        ], $event->old_values);

        $this->assertSame([
            'permissions' => ['accounts.view', 'accounts.view_any', 'projects.view'],
            'permissions_added' => ['accounts.view', 'accounts.view_any'],
            'permissions_removed' => ['projects.view_any'],
            'role_id' => $role->id,
        ], $event->new_values);

        $this->assertSame(['permissions'], $event->changed_fields);
    }

    public function test_renaming_a_role_and_changing_its_permissions_is_one_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();
        $this->permissions(['projects.view', 'accounts.view']);

        $role = $this->role('الاسم القديم', ['projects.view']);

        $this->roles()->updateRole($actor, $role, [
            'name' => 'الاسم الجديد',
            'permissions' => ['projects.view', 'accounts.view'],
        ]);

        $event = $this->onlySecurityEvent('role', 'updated');

        $this->assertSame(['name', 'permissions'], $event->changed_fields);
        $this->assertSame('الاسم القديم', $event->old_values['name']);
        $this->assertSame('الاسم الجديد', $event->new_values['name']);
        $this->assertSame(['accounts.view'], $event->new_values['permissions_added']);
        $this->assertSame([], $event->new_values['permissions_removed']);
    }

    public function test_permission_arrays_are_deterministic_regardless_of_submission_order(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();
        $this->permissions(['projects.view', 'accounts.view']);

        $first = $this->role('دور أول');
        $second = $this->role('دور ثانٍ');

        $this->roles()->updateRole($actor, $first, ['permissions' => ['projects.view', 'accounts.view']]);
        $this->roles()->updateRole($actor, $second, ['permissions' => ['accounts.view', 'projects.view']]);

        $events = $this->securityEvents('role', 'updated');

        $this->assertCount(2, $events);
        $this->assertSame(['accounts.view', 'projects.view'], $events[0]->new_values['permissions']);
        $this->assertSame($events[0]->new_values['permissions'], $events[1]->new_values['permissions']);
        $this->assertSame($events[0]->new_values['permissions_added'], $events[1]->new_values['permissions_added']);
    }

    public function test_an_update_that_changes_nothing_writes_no_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();
        $this->permissions(['projects.view']);

        $role = $this->role('دور ثابت', ['projects.view']);

        $this->roles()->updateRole($actor, $role, [
            'name' => 'دور ثابت',
            'permissions' => ['projects.view'],
        ]);

        $this->assertCount(0, $this->securityEvents('role'));
    }

    // ---- delete ---------------------------------------------------------

    public function test_deleting_a_role_writes_one_event_carrying_the_pre_delete_permissions(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();
        $this->permissions(['projects.view', 'accounts.view']);

        $role = $this->role('دور للحذف', ['projects.view', 'accounts.view']);
        $roleId = $role->id;

        $this->roles()->deleteRole($actor, $role);

        $event = $this->onlySecurityEvent('role', 'deleted');

        $this->assertSame((string) $roleId, $event->subject_key);
        $this->assertSame('دور للحذف', $event->subject_label);
        $this->assertNull($event->new_values);
        $this->assertSame([
            'role_id' => $roleId,
            'name' => 'دور للحذف',
            'permissions' => ['accounts.view', 'projects.view'],
        ], $event->old_values);

        $this->assertNull(Role::find($roleId));
    }

    // ---- unchanged authorization rules -----------------------------------

    /**
     * Auditing must not have loosened a single existing rule: a system role
     * still cannot be edited or deleted, even by a real Super Admin, and a
     * rejected attempt writes no audit event either.
     */
    public function test_a_rejected_system_role_update_writes_no_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();

        $systemRole = Role::where('name', 'Super Admin')->firstOrFail();

        try {
            $this->roles()->updateRole($actor, $systemRole, ['name' => 'محاولة']);
            $this->fail('Expected a system role update to be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            // expected
        }

        $this->assertCount(0, $this->securityEvents());
        $this->assertSame('Super Admin', $systemRole->fresh()->name);
    }

    public function test_a_rejected_role_deletion_writes_no_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();

        $role = $this->role('دور مستخدم');
        $holder = User::factory()->create();
        $holder->syncRoles(['دور مستخدم']);

        try {
            $this->roles()->deleteRole($actor, $role);
            $this->fail('Expected the deletion of an assigned role to be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            // expected
        }

        $this->assertCount(0, $this->securityEvents());
        $this->assertNotNull(Role::find($role->id));
    }

    public function test_role_events_use_the_stable_alias_never_a_class_name(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->grantRoleManagement();

        $this->roles()->createRole($actor, ['name' => 'دور بسيط', 'permissions' => []]);

        $event = $this->onlySecurityEvent('role', 'created');

        $this->assertSame('role', $event->subject_type);
        $this->assertStringNotContainsString('Spatie', (string) $event->subject_type);
    }
}
