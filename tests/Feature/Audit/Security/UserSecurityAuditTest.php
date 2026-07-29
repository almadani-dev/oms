<?php

namespace Tests\Feature\Audit\Security;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;

/**
 * OMS Task 9B.4 — the audited User write paths (UserManagementService, the
 * only place UserResource's pages and table actions mutate a user).
 */
class UserSecurityAuditTest extends SecurityAuditTestCase
{
    // ---- create ---------------------------------------------------------

    public function test_creating_a_user_writes_one_security_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Accountant');

        $created = $this->users()->createUser($actor, [
            'name' => 'محاسب جديد',
            'email' => 'accountant@example.test',
            'password' => 'secret-password-value',
            'roles' => ['Accountant'],
        ]);

        $event = $this->onlySecurityEvent('user', 'created');

        $this->assertSame('security', $event->event_category);
        $this->assertSame((string) $created->id, $event->subject_key);
        $this->assertSame('محاسب جديد — accountant@example.test', $event->subject_label);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertNull($event->old_values);

        $this->assertSame([
            'user_id' => $created->id,
            'name' => 'محاسب جديد',
            'email' => 'accountant@example.test',
            'is_active' => true,
            'roles' => ['Accountant'],
            'direct_permissions' => [],
            'password_changed' => true,
        ], $event->new_values);
    }

    public function test_a_created_users_password_never_reaches_the_audit_log(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $created = $this->users()->createUser($actor, [
            'name' => 'مستخدم',
            'email' => 'user@example.test',
            'password' => 'plaintext-must-not-appear',
        ]);

        $this->assertEventContainsNone($this->onlySecurityEvent('user', 'created'), [
            'plaintext-must-not-appear',
            $created->fresh()->password,
            '$2y$',
        ]);
    }

    // ---- update ---------------------------------------------------------

    /**
     * The core "one logical action = one event" case for this phase: a single
     * UserResource form submission that renames the user, deactivates them,
     * replaces both of their roles AND resets their password must produce ONE
     * event describing all four, not four events and not one per pivot row.
     */
    public function test_renaming_deactivating_replacing_roles_and_resetting_the_password_is_one_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Accountant');
        $this->role('Viewer');
        $this->role('Admin');

        $target = User::factory()->create(['name' => 'الاسم القديم', 'is_active' => true]);
        $target->syncRoles(['Accountant', 'Viewer']);

        $this->users()->updateUser($actor, $target, [
            'name' => 'الاسم الجديد',
            'is_active' => false,
            'password' => 'a-brand-new-password',
            'roles' => ['Admin', 'Viewer'],
        ]);

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame([
            'name' => 'الاسم القديم',
            'is_active' => true,
            'roles' => ['Accountant', 'Viewer'],
        ], $event->old_values);

        $this->assertSame([
            'name' => 'الاسم الجديد',
            'is_active' => false,
            'roles' => ['Admin', 'Viewer'],
            'roles_added' => ['Admin'],
            'roles_removed' => ['Accountant'],
            'password_changed' => true,
        ], $event->new_values);

        $this->assertSame(['name', 'is_active', 'roles', 'password_changed'], $event->changed_fields);
    }

    public function test_deactivating_a_user_records_the_activation_change_only(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $target = User::factory()->create(['is_active' => true]);

        $this->users()->updateUser($actor, $target, ['is_active' => false]);

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame(['is_active' => true], $event->old_values);
        $this->assertSame(['is_active' => false], $event->new_values);
        $this->assertSame(['is_active'], $event->changed_fields);
        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_reactivating_a_user_records_the_activation_change(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $target = User::factory()->create(['is_active' => false]);

        $this->users()->updateUser($actor, $target, ['is_active' => true]);

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame(['is_active' => false], $event->old_values);
        $this->assertSame(['is_active' => true], $event->new_values);
    }

    /**
     * A password change records the single boolean flag and nothing else —
     * no plaintext, no old hash, no new hash, no confirmation field.
     */
    public function test_a_password_change_records_only_password_changed_true(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $target = User::factory()->create();
        $oldHash = $target->password;

        $this->users()->updateUser($actor, $target, [
            'password' => 'the-new-plaintext-password',
            'password_confirmation' => 'the-new-plaintext-password',
        ]);

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame(['password_changed' => true], $event->new_values);
        $this->assertSame([], $event->old_values);
        $this->assertSame(['password_changed'], $event->changed_fields);

        $this->assertEventContainsNone($event, [
            'the-new-plaintext-password',
            $oldHash,
            $target->fresh()->password,
            '$2y$',
        ]);
    }

    public function test_a_self_password_change_is_audited_as_one_event(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $this->users()->updateUser($actor, $actor, ['password' => 'my-own-new-password']);

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame((string) $actor->id, $event->subject_key);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame(['password_changed' => true], $event->new_values);
        $this->assertEventContainsNone($event, ['my-own-new-password', '$2y$']);
    }

    public function test_an_update_that_changes_nothing_writes_no_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $target = User::factory()->create(['name' => 'بدون تغيير']);

        $this->users()->updateUser($actor, $target, ['name' => 'بدون تغيير']);

        $this->assertCount(0, $this->securityEvents('user'));
    }

    /**
     * A blank password field is not dehydrated by UserForm, so it never
     * reaches the service — and even when an empty string is forced through,
     * the existing hash is untouched and no password_changed flag is written.
     */
    public function test_a_blank_password_is_not_recorded_as_a_password_change(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $target = User::factory()->create(['name' => 'اسم']);

        $this->users()->updateUser($actor, $target, ['name' => 'اسم آخر', 'password' => '']);

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertArrayNotHasKey('password_changed', $event->new_values);
        $this->assertSame(['name'], $event->changed_fields);
    }

    // ---- role assignment via the user path -------------------------------

    public function test_assigning_roles_produces_one_event_not_one_per_pivot_row(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Admin');
        $this->role('Accountant');
        $this->role('Viewer');

        $target = User::factory()->create();

        $this->users()->updateUser($actor, $target, ['roles' => ['Viewer', 'Admin', 'Accountant']]);

        $this->assertSame(3, DB::table('model_has_roles')->where('model_id', $target->id)->count());

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame(['roles' => []], $event->old_values);
        $this->assertSame([
            'roles' => ['Accountant', 'Admin', 'Viewer'],
            'roles_added' => ['Accountant', 'Admin', 'Viewer'],
            'roles_removed' => [],
        ], $event->new_values);
    }

    public function test_removing_every_role_produces_one_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Admin');
        $this->role('Viewer');

        $target = User::factory()->create();
        $target->syncRoles(['Admin', 'Viewer']);

        $this->users()->updateUser($actor, $target, ['roles' => []]);

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame(['roles' => ['Admin', 'Viewer']], $event->old_values);
        $this->assertSame([
            'roles' => [],
            'roles_added' => [],
            'roles_removed' => ['Admin', 'Viewer'],
        ], $event->new_values);
    }

    /**
     * Submission ORDER must never change the stored payload — the same
     * logical role set produces byte-identical arrays, so a diff between two
     * audit rows always reflects a real privilege change.
     */
    public function test_role_arrays_are_deterministic_regardless_of_submission_order(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Admin');
        $this->role('Accountant');

        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->users()->updateUser($actor, $first, ['roles' => ['Admin', 'Accountant']]);
        $this->users()->updateUser($actor, $second, ['roles' => ['Accountant', 'Admin']]);

        $events = $this->securityEvents('user', 'updated');

        $this->assertCount(2, $events);
        $this->assertSame($events[0]->new_values['roles'], $events[1]->new_values['roles']);
        $this->assertSame(['Accountant', 'Admin'], $events[0]->new_values['roles']);
    }

    public function test_a_resubmitted_identical_role_set_writes_no_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Admin');

        $target = User::factory()->create();
        $target->syncRoles(['Admin']);

        $this->users()->updateUser($actor, $target, ['roles' => ['Admin']]);

        $this->assertCount(0, $this->securityEvents('user'));
    }

    // ---- direct permissions ---------------------------------------------

    /**
     * Direct (non-role) permissions are part of the user snapshot, so any
     * change to them is carried by the SAME single event as the rest of the
     * update. No UserResource path mutates them today — UserForm exposes
     * roles only, and nothing in app/ calls givePermissionTo()/
     * revokePermissionTo() on a user — so this asserts the contract at the
     * snapshot level: the before/after state is genuinely read from
     * model_has_permissions, not assumed empty.
     */
    public function test_direct_permissions_are_captured_in_the_user_snapshot(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $target = User::factory()->create();
        $target->givePermissionTo($this->permission('projects.view'));
        $target->givePermissionTo($this->permission('accounts.view'));

        $this->users()->updateUser($actor, $target, ['is_active' => false]);

        $event = $this->onlySecurityEvent('user', 'updated');

        // Unchanged by this submission, so correctly absent from the diff...
        $this->assertArrayNotHasKey('direct_permissions', $event->new_values);

        // ...but really present on the deletion snapshot, which is a full
        // snapshot rather than a diff.
        $this->users()->deleteUser($actor, $target->fresh());

        $this->assertSame(
            ['accounts.view', 'projects.view'],
            $this->onlySecurityEvent('user', 'deleted')->old_values['direct_permissions'],
        );
    }

    // ---- delete / restore ------------------------------------------------

    public function test_deleting_a_user_writes_one_event_carrying_the_pre_delete_snapshot(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Viewer');

        $target = User::factory()->create(['name' => 'مستخدم محذوف', 'email' => 'gone@example.test']);
        $target->syncRoles(['Viewer']);

        $this->users()->deleteUser($actor, $target);

        $event = $this->onlySecurityEvent('user', 'deleted');

        $this->assertSame((string) $target->id, $event->subject_key);
        $this->assertSame('مستخدم محذوف — gone@example.test', $event->subject_label);
        $this->assertNull($event->new_values);
        $this->assertSame([
            'user_id' => $target->id,
            'name' => 'مستخدم محذوف',
            'email' => 'gone@example.test',
            'is_active' => true,
            'roles' => ['Viewer'],
            'direct_permissions' => [],
        ], $event->old_values);

        $this->assertNotNull(User::withTrashed()->find($target->id)->deleted_at);
    }

    public function test_restoring_a_user_writes_one_event(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $target = User::factory()->create(['name' => 'عائد']);
        $target->delete();

        $this->users()->restoreUser($actor, User::withTrashed()->findOrFail($target->id));

        $event = $this->onlySecurityEvent('user', 'restored');

        $this->assertSame((string) $target->id, $event->subject_key);
        $this->assertNull($event->old_values);
        $this->assertSame($target->id, $event->new_values['user_id']);
        $this->assertNull(User::withTrashed()->find($target->id)->deleted_at);
    }

    // ---- no leakage anywhere ---------------------------------------------

    public function test_no_credential_or_session_field_ever_appears_in_any_user_event(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $this->role('Admin');

        $created = $this->users()->createUser($actor, [
            'name' => 'دورة كاملة',
            'email' => 'lifecycle@example.test',
            'password' => 'lifecycle-secret',
            'roles' => ['Admin'],
        ]);

        $this->users()->updateUser($actor, $created, ['password' => 'lifecycle-secret-two']);
        $this->users()->deleteUser($actor, $created->fresh());
        $this->users()->restoreUser($actor, User::withTrashed()->findOrFail($created->id));

        $events = $this->securityEvents('user');
        $this->assertCount(4, $events);

        foreach ($events as $event) {
            $this->assertEventContainsNone($event, [
                'lifecycle-secret',
                'lifecycle-secret-two',
                '$2y$',
                'remember_token',
                'password_confirmation',
                'session_id',
            ]);
        }
    }

    /**
     * The actor snapshot is role NAMES only — never an effective-permission
     * dump (AuditActorContext queries no permissions at all).
     */
    public function test_the_actor_snapshot_carries_role_names_only(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $this->users()->createUser($actor, [
            'name' => 'مستخدم',
            'email' => 'actor-check@example.test',
            'password' => 'password-value',
        ]);

        $event = $this->onlySecurityEvent('user', 'created');

        $this->assertSame([PermissionRegistry::SUPER_ADMIN], $event->actor_roles);
        $this->assertSame($actor->email, $event->actor_email);
        $this->assertSame('user', $event->actor_type->value);
    }

    public function test_every_user_event_uses_the_stable_alias_never_a_class_name(): void
    {
        $actor = $this->actingAsSuperAdmin();

        $target = $this->users()->createUser($actor, [
            'name' => 'اسم',
            'email' => 'alias@example.test',
            'password' => 'password-value',
        ]);

        $this->users()->deleteUser($actor, $target->fresh());

        foreach (AuditEvent::all() as $event) {
            $this->assertSame('user', $event->subject_type);
            $this->assertStringNotContainsString('\\', (string) $event->subject_type);
            $this->assertStringNotContainsString('App\\', (string) $event->subject_type);
        }
    }
}
