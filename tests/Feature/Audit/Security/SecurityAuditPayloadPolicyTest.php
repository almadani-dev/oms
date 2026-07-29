<?php

namespace Tests\Feature\Audit\Security;

use App\Models\User;
use App\Services\Audit\AuditRedactor;
use App\Services\Audit\Security\SecurityAuditRecorder;
use App\Services\Audit\Security\SecurityAuditSubject;
use App\Services\Audit\Security\SecurityNameDiff;
use App\Services\Audit\Security\UserSecuritySnapshot;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * OMS Task 9B.4 — the payload contract itself, tested at the recorder/diff
 * level rather than through a UI path.
 *
 * DIRECT USER PERMISSIONS are covered here for a specific, documented reason:
 * this application has no write path that assigns or revokes a permission
 * DIRECTLY on a user. UserForm exposes roles only, and nothing anywhere in
 * app/ calls givePermissionTo()/revokePermissionTo()/syncPermissions() on a
 * User (verified across the whole tree). The snapshot, the diff and the
 * one-event payload are implemented and asserted here so that the moment such
 * a path is added it is already audited correctly — but no such path is
 * invented by this phase, which would mean creating a new privilege-granting
 * surface the application does not currently have.
 */
class SecurityAuditPayloadPolicyTest extends SecurityAuditTestCase
{
    private function recorder(): SecurityAuditRecorder
    {
        return app(SecurityAuditRecorder::class);
    }

    // ---- direct permission diffs -----------------------------------------

    public function test_a_direct_permission_change_produces_one_event_with_a_full_diff(): void
    {
        $actor = $this->actingAsSuperAdmin();
        $target = User::factory()->create();

        $before = UserSecuritySnapshot::of($target);

        $target->givePermissionTo($this->permission('projects.view'));
        $target->givePermissionTo($this->permission('accounts.view'));

        DB::transaction(function () use ($target, $before): void {
            $this->recorder()->userUpdated($target, $before, UserSecuritySnapshot::of($target), false);
        });

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame(['direct_permissions' => []], $event->old_values);
        $this->assertSame([
            'direct_permissions' => ['accounts.view', 'projects.view'],
            'permissions_added' => ['accounts.view', 'projects.view'],
            'permissions_removed' => [],
        ], $event->new_values);
        $this->assertSame(['direct_permissions'], $event->changed_fields);
        $this->assertSame($actor->id, $event->actor_user_id);
    }

    public function test_revoking_a_direct_permission_produces_one_event(): void
    {
        $this->actingAsSuperAdmin();
        $target = User::factory()->create();
        $target->givePermissionTo($this->permission('projects.view'));
        $target->givePermissionTo($this->permission('accounts.view'));

        $before = UserSecuritySnapshot::of($target);

        $target->revokePermissionTo('accounts.view');

        DB::transaction(function () use ($target, $before): void {
            $this->recorder()->userUpdated($target, $before, UserSecuritySnapshot::of($target), false);
        });

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame(['accounts.view', 'projects.view'], $event->old_values['direct_permissions']);
        $this->assertSame(['projects.view'], $event->new_values['direct_permissions']);
        $this->assertSame([], $event->new_values['permissions_added']);
        $this->assertSame(['accounts.view'], $event->new_values['permissions_removed']);
    }

    public function test_a_combined_role_and_direct_permission_change_is_still_one_event(): void
    {
        $this->actingAsSuperAdmin();
        $this->role('Admin');

        $target = User::factory()->create();
        $before = UserSecuritySnapshot::of($target);

        $target->syncRoles(['Admin']);
        $target->givePermissionTo($this->permission('projects.view'));

        DB::transaction(function () use ($target, $before): void {
            $this->recorder()->userUpdated($target, $before, UserSecuritySnapshot::of($target), true);
        });

        $event = $this->onlySecurityEvent('user', 'updated');

        $this->assertSame(['roles', 'direct_permissions', 'password_changed'], $event->changed_fields);
        $this->assertSame(['Admin'], $event->new_values['roles_added']);
        $this->assertSame(['projects.view'], $event->new_values['permissions_added']);
        $this->assertTrue($event->new_values['password_changed']);
    }

    // ---- the diff primitive ----------------------------------------------

    public function test_the_diff_is_sorted_deduplicated_and_reindexed(): void
    {
        $diff = SecurityNameDiff::between(
            ['Viewer', 'Admin', 'Viewer'],
            ['Accountant', 'Admin'],
        );

        $this->assertSame(['Admin', 'Viewer'], $diff->before);
        $this->assertSame(['Accountant', 'Admin'], $diff->after);
        $this->assertSame(['Accountant'], $diff->added);
        $this->assertSame(['Viewer'], $diff->removed);
    }

    public function test_a_reordered_set_is_not_a_change(): void
    {
        $this->assertTrue(
            SecurityNameDiff::between(['Admin', 'Viewer'], ['Viewer', 'Admin'])->isEmpty(),
        );
    }

    public function test_the_diff_produces_list_arrays_that_survive_a_json_round_trip(): void
    {
        $diff = SecurityNameDiff::between(['b', 'a', 'c'], ['c']);

        // array_diff preserves original keys; without array_values() these
        // would encode as JSON OBJECTS ({"1":"a"}) instead of arrays.
        $this->assertSame('["a","b"]', json_encode($diff->removed));
        $this->assertSame('["c"]', json_encode($diff->after));
    }

    // ---- the closed field allowlist ---------------------------------------

    public function test_the_user_snapshot_exposes_only_the_six_allowlisted_fields(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            ['user_id', 'name', 'email', 'is_active', 'roles', 'direct_permissions'],
            array_keys(UserSecuritySnapshot::of($user)),
        );
    }

    public function test_the_snapshot_never_reads_the_password_or_remember_token(): void
    {
        $user = User::factory()->create();
        $user->setRememberToken('a-remember-token-value');
        $user->save();

        $encoded = json_encode(UserSecuritySnapshot::of($user->fresh()));

        $this->assertStringNotContainsString('a-remember-token-value', (string) $encoded);
        $this->assertStringNotContainsString('$2y$', (string) $encoded);
        $this->assertStringNotContainsString('password', (string) $encoded);
    }

    /**
     * The redactor is the second, independent layer: even if a future caller
     * hands the recorder a raw credential field, it is erased before storage.
     * `password_changed` is the one explicitly allowlisted exception, because
     * it is a boolean flag that exists precisely so the credential never has
     * to be represented.
     */
    public function test_the_redactor_erases_credentials_but_keeps_the_password_changed_flag(): void
    {
        $redacted = app(AuditRedactor::class)->redact([
            'password' => 'plaintext',
            'password_confirmation' => 'plaintext',
            'remember_token' => 'token-value',
            'session_id' => 'session-value',
            'authorization' => 'Bearer abc',
            'password_changed' => true,
            'direct_permissions' => ['projects.view'],
            'roles' => ['Admin'],
        ]);

        $this->assertSame(AuditRedactor::MARKER, $redacted['password']);
        $this->assertSame(AuditRedactor::MARKER, $redacted['password_confirmation']);
        $this->assertSame(AuditRedactor::MARKER, $redacted['remember_token']);
        $this->assertSame(AuditRedactor::MARKER, $redacted['session_id']);
        $this->assertSame(AuditRedactor::MARKER, $redacted['authorization']);

        $this->assertTrue($redacted['password_changed']);
        $this->assertSame(['projects.view'], $redacted['direct_permissions']);
        $this->assertSame(['Admin'], $redacted['roles']);
    }

    // ---- stable aliases ---------------------------------------------------

    public function test_every_security_subject_alias_is_a_short_snake_case_token(): void
    {
        foreach (SecurityAuditSubject::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $case->value);
            $this->assertStringNotContainsString('\\', $case->value);
            $this->assertLessThanOrEqual(100, strlen($case->value));
        }

        $this->assertSame(
            ['user', 'role', 'permission', 'authentication', 'permission_sync'],
            array_map(fn (SecurityAuditSubject $case): string => $case->value, SecurityAuditSubject::cases()),
        );
    }

    // ---- fail-closed transaction requirement ------------------------------

    /**
     * A REQUIRED security event recorded outside the caller's transaction
     * could survive a rolled-back privilege change. The recorder refuses
     * rather than writing it.
     */
    public function test_a_required_security_event_outside_a_transaction_fails_closed(): void
    {
        $this->actingAsSuperAdmin();
        $user = User::factory()->create();

        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);

        $this->recorder()->userCreated($user);
    }

    public function test_the_no_op_update_path_also_requires_a_transaction(): void
    {
        $this->actingAsSuperAdmin();
        $user = User::factory()->create();
        $snapshot = UserSecuritySnapshot::of($user);

        $this->expectException(LogicException::class);

        $this->recorder()->userUpdated($user, $snapshot, $snapshot, false);
    }
}
