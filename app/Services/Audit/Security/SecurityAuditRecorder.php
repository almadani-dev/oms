<?php

namespace App\Services\Audit\Security;

use App\Enums\AuditFailureMode;
use App\Models\User;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;

/**
 * The single write path for the REQUIRED half of `event_category = security`
 * (OMS Task 9B.4): user CRUD, activation/deactivation, administrator password
 * changes, role assignment, direct-permission assignment, role-permission
 * management and permission synchronisation.
 *
 * Authentication events (login/logout) are NOT written here — they are
 * best-effort by definition and belong to AuthenticationAuditRecorder. The
 * split is deliberate and structural: this class cannot write a best-effort
 * event and that one cannot write a required one, so a wiring mistake cannot
 * quietly downgrade a security mutation's atomicity guarantee.
 *
 * RULE 1 — the audit insert belongs to the CALLER's transaction.
 * Like App\Services\Audit\Financial\FinancialAuditRecorder and unlike
 * App\Services\Audit\Crud\AuditedCrudService, this class NEVER opens a
 * transaction. Every caller (UserManagementService, RoleManagementService,
 * PermissionSyncService) already wraps its whole logical action — the model
 * save, the Spatie pivot sync, the last-active-Super-Admin lock — in one
 * DB::transaction(); opening a second one here would let a REQUIRED audit
 * failure roll back nothing but itself. Each entry point therefore asserts a
 * transaction is genuinely open and fails closed, so a security mutation can
 * never commit without its audit row.
 *
 * RULE 2 — one logical action = exactly one event.
 * Creating a user, setting their password and assigning them two roles is ONE
 * `security.created` event on subject `user`, not one per field and not one
 * per `model_has_roles` pivot row. There is deliberately no observer on
 * Spatie's pivot models and no `Role`/`Permission` entry in the general-CRUD
 * AuditSubjectRegistry, so a pivot write has no independent audit path at all
 * — duplicates are structurally impossible rather than merely avoided.
 */
final class SecurityAuditRecorder
{
    public const EVENT_CATEGORY = 'security';

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AuditActorResolver $actorResolver,
    ) {}

    // ---- users ---------------------------------------------------------

    /**
     * A password is always set when an account is created, so
     * `password_changed` is unconditionally true here — it records that a
     * credential was established, never any part of the credential itself.
     */
    public function userCreated(User $user): void
    {
        $this->record(
            subject: SecurityAuditSubject::User,
            action: 'created',
            subjectKey: $user->getKey(),
            subjectLabel: UserSecuritySnapshot::label($user),
            oldValues: null,
            newValues: UserSecuritySnapshot::of($user) + ['password_changed' => true],
            changedFields: null,
        );
    }

    /**
     * Writes nothing when nothing an audit cares about actually changed — a
     * form re-save that touches no allowlisted field, no role, no direct
     * permission and no password is not an auditable state change.
     *
     * $before/$after are UserSecuritySnapshot::of() results captured either
     * side of every mutation in the caller's transaction, so a single form
     * submission that renames the user, deactivates them AND replaces their
     * roles produces exactly one event describing all three.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function userUpdated(User $user, array $before, array $after, bool $passwordChanged): void
    {
        $old = [];
        $new = [];
        $changed = [];

        foreach (['name', 'email', 'is_active'] as $field) {
            if (($before[$field] ?? null) === ($after[$field] ?? null)) {
                continue;
            }

            $old[$field] = $before[$field] ?? null;
            $new[$field] = $after[$field] ?? null;
            $changed[] = $field;
        }

        $roles = SecurityNameDiff::between($before['roles'] ?? [], $after['roles'] ?? []);

        if (! $roles->isEmpty()) {
            $old['roles'] = $roles->before;
            $new['roles'] = $roles->after;
            $new['roles_added'] = $roles->added;
            $new['roles_removed'] = $roles->removed;
            $changed[] = 'roles';
        }

        $permissions = SecurityNameDiff::between(
            $before['direct_permissions'] ?? [],
            $after['direct_permissions'] ?? [],
        );

        if (! $permissions->isEmpty()) {
            $old['direct_permissions'] = $permissions->before;
            $new['direct_permissions'] = $permissions->after;
            $new['permissions_added'] = $permissions->added;
            $new['permissions_removed'] = $permissions->removed;
            $changed[] = 'direct_permissions';
        }

        if ($passwordChanged) {
            // The ONLY thing ever recorded about a password change. No old
            // hash, no new hash, no plaintext, no confirmation field — see
            // UserSecuritySnapshot, which never reads the attribute at all.
            $new['password_changed'] = true;
            $changed[] = 'password_changed';
        }

        if ($changed === []) {
            $this->assertInsideCallerTransaction('updated');

            return;
        }

        $this->record(
            subject: SecurityAuditSubject::User,
            action: 'updated',
            subjectKey: $user->getKey(),
            subjectLabel: UserSecuritySnapshot::label($user),
            oldValues: $old,
            newValues: $new,
            changedFields: $changed,
        );
    }

    /**
     * $snapshot must have been captured BEFORE the soft delete, while the
     * user's roles and direct permissions were still readable — that
     * pre-delete snapshot is the only remaining description of what access
     * the removed account held.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function userDeleted(User $user, array $snapshot, ?string $label): void
    {
        $this->record(
            subject: SecurityAuditSubject::User,
            action: 'deleted',
            subjectKey: $user->getKey(),
            subjectLabel: $label,
            oldValues: $snapshot,
            newValues: null,
            changedFields: null,
        );
    }

    public function userRestored(User $user): void
    {
        $this->record(
            subject: SecurityAuditSubject::User,
            action: 'restored',
            subjectKey: $user->getKey(),
            subjectLabel: UserSecuritySnapshot::label($user),
            oldValues: null,
            newValues: UserSecuritySnapshot::of($user),
            changedFields: null,
        );
    }

    // ---- roles ---------------------------------------------------------

    /**
     * @param  list<string>  $permissionNames
     */
    public function roleCreated(Role $role, array $permissionNames): void
    {
        $this->record(
            subject: SecurityAuditSubject::Role,
            action: 'created',
            subjectKey: $role->getKey(),
            subjectLabel: $role->name,
            oldValues: null,
            newValues: [
                'role_id' => $role->getKey(),
                'name' => $role->name,
                'permissions' => SecurityNameDiff::normalize($permissionNames),
            ],
            changedFields: null,
        );
    }

    /**
     * Renaming a role and replacing its entire permission set in one form
     * submission is ONE event carrying both, never one per changed pivot row.
     *
     * @param  array{name: ?string, permissions: array<int, string>}  $before
     * @param  array{name: ?string, permissions: array<int, string>}  $after
     */
    public function roleUpdated(Role $role, array $before, array $after): void
    {
        $old = [];
        $new = [];
        $changed = [];

        if (($before['name'] ?? null) !== ($after['name'] ?? null)) {
            $old['name'] = $before['name'] ?? null;
            $new['name'] = $after['name'] ?? null;
            $changed[] = 'name';
        }

        $permissions = SecurityNameDiff::between($before['permissions'] ?? [], $after['permissions'] ?? []);

        if (! $permissions->isEmpty()) {
            $old['permissions'] = $permissions->before;
            $new['permissions'] = $permissions->after;
            $new['permissions_added'] = $permissions->added;
            $new['permissions_removed'] = $permissions->removed;
            $changed[] = 'permissions';
        }

        if ($changed === []) {
            $this->assertInsideCallerTransaction('updated');

            return;
        }

        $new['role_id'] = $role->getKey();

        $this->record(
            subject: SecurityAuditSubject::Role,
            action: 'updated',
            subjectKey: $role->getKey(),
            subjectLabel: $role->name,
            oldValues: $old,
            newValues: $new,
            changedFields: $changed,
        );
    }

    /**
     * Spatie's Role has no SoftDeletes, so the row and its
     * `role_has_permissions` pivots are really gone after this — $snapshot
     * must have been captured while they still existed.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function roleDeleted(Role $role, array $snapshot, ?string $label): void
    {
        $this->record(
            subject: SecurityAuditSubject::Role,
            action: 'deleted',
            subjectKey: $role->getKey(),
            subjectLabel: $label,
            oldValues: $snapshot,
            newValues: null,
            changedFields: null,
        );
    }

    // ---- permission synchronisation ------------------------------------

    /**
     * ONE bounded summary event for a whole synchronisation run, not one per
     * permission row created and not one per system role reconciled — a run
     * touches every registry permission and all five system roles, so
     * row-level events would produce hundreds of rows describing a single
     * administrative action.
     *
     * A no-op run (nothing created, every role already reconciled) still
     * writes this event: running the security-administration command is
     * itself the accountable act, independently of whether it changed
     * anything.
     *
     * @param  array<string, mixed>  $summary
     */
    public function permissionsSynced(array $summary, ?string $correlationId = null): void
    {
        $this->record(
            subject: SecurityAuditSubject::PermissionSync,
            action: 'synced',
            subjectKey: null,
            subjectLabel: null,
            oldValues: null,
            newValues: $summary,
            changedFields: null,
            correlationId: $correlationId,
        );
    }

    // ---- plumbing ------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int, string>|null  $changedFields
     */
    private function record(
        SecurityAuditSubject $subject,
        string $action,
        int|string|null $subjectKey,
        ?string $subjectLabel,
        ?array $oldValues,
        ?array $newValues,
        ?array $changedFields,
        ?string $correlationId = null,
    ): void {
        $this->assertInsideCallerTransaction($action);

        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: $action,
                actor: $this->actorResolver->resolve(),
                subjectType: $subject->value,
                subjectKey: $subjectKey === null ? null : (string) $subjectKey,
                subjectLabel: $subjectLabel,
                newValues: $newValues,
                oldValues: $oldValues,
                changedFields: $changedFields,
                correlationId: $correlationId,
            ),
            AuditFailureMode::Required,
        );
    }

    /**
     * Fails closed rather than quietly writing an audit row that could
     * survive a rolled-back security mutation. Every call site records from
     * inside its own already-open DB::transaction(); a violation here is a
     * wiring bug, caught at the call site, never a silently unaudited
     * privilege change.
     */
    private function assertInsideCallerTransaction(string $action): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(sprintf(
                'A security audit event (%s.%s) must be recorded from inside the caller\'s own open DB::transaction().',
                self::EVENT_CATEGORY,
                $action,
            ));
        }
    }
}
