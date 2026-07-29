<?php

namespace App\Services\Audit\Security;

use App\Enums\AuditFailureMode;
use App\Enums\AuditStatus;
use App\Models\User;
use App\Services\Audit\AuditActorContext;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;

/**
 * The single write path for the three BEST-EFFORT `event_category = security`
 * events (OMS Task 9B.4): `login_success`, `login_failed`, `logout`.
 *
 * WHY BEST-EFFORT, AND WHY A SEPARATE CLASS FROM SecurityAuditRecorder.
 * All three describe something that has ALREADY happened by the time Laravel
 * dispatches the event: `Login` fires after SessionGuard has written the
 * session, `Logout` fires after the session has already been cleared, and
 * `Failed` fires after the credentials have already been rejected. There is
 * nothing left to roll back, and nothing these events could usefully take
 * down with them. Making them REQUIRED would be actively harmful in the two
 * directions that matter most:
 *
 *  - a failed audit insert on `Logout` would throw out of Filament's
 *    LogoutController AFTER the session was destroyed, leaving the user
 *    unable to complete a legitimate sign-out;
 *  - a failed audit insert on `Login`/`Failed` would throw out of the login
 *    page after the session was already regenerated, turning an audit-storage
 *    outage into a login loop that locks every administrator out of the
 *    system — including the ones who would need to log in to FIX the audit
 *    storage.
 *
 * A failure here is therefore swallowed by AuditFailureMode::BestEffort and
 * reported through AuditLogger's sanitized error log instead. Because this
 * class physically cannot emit a REQUIRED event and SecurityAuditRecorder
 * physically cannot emit a best-effort one, that decision cannot be undone by
 * a later edit at a call site.
 *
 * DUPLICATE PREVENTION.
 * One logical login attempt can dispatch `Failed` TWICE. When credentials are
 * valid but User::canAccessPanel() denies entry (a deactivated or
 * soft-deleted account), Illuminate\Auth\SessionGuard::attemptWhen() fires
 * `Failed` when its callback returns false, and Filament\Auth\Pages\Login
 * then fires `Failed` again itself before throwing the validation exception
 * (verified against filament/filament v5.6.7, Login::authenticate() lines
 * 151-160). That is one attempt, so it must be one event.
 *
 * The de-duplication window is one ATTEMPT, not one request and not a time
 * window: beginAttempt() is called from the `Attempting` listener, which
 * Laravel/Filament dispatch exactly once at the start of every authentication
 * attempt and never between a pair of duplicate `Failed` events. That makes
 * two consecutive login attempts in the same request (a test, or a future
 * multi-step flow) two events, while the duplicate pair stays one — without
 * depending on request-object identity or wall-clock timing.
 */
final class AuthenticationAuditRecorder
{
    /**
     * Set by beginAttempt() and cleared as each event is recorded. Reset —
     * not accumulated — so this can never grow across a long-lived worker
     * process.
     *
     * @var array<string, true>
     */
    private array $recordedThisAttempt = [];

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AuditActorResolver $actorResolver,
    ) {}

    /**
     * Opens a fresh de-duplication window. Idempotent by design: Filament and
     * SessionGuard each dispatch `Attempting` once for the same attempt, and
     * no `Failed`/`Login` event is ever dispatched between the two, so the
     * second reset always clears an already-empty set.
     */
    public function beginAttempt(): void
    {
        $this->recordedThisAttempt = [];
    }

    public function loginSucceeded(User $user): void
    {
        if (! $this->claim('login_success')) {
            return;
        }

        $this->record(
            action: 'login_success',
            status: AuditStatus::Success,
            actor: $this->actorResolver->forUser($user),
            subjectKey: $user->getKey(),
            subjectLabel: UserSecuritySnapshot::label($user),
            newValues: [
                'user_id' => $user->getKey(),
                'is_active' => (bool) $user->is_active,
                'roles' => UserSecuritySnapshot::roleNames($user),
            ],
        );
    }

    /**
     * $user is the account Laravel's own user provider resolved from the
     * submitted credentials — null when the submitted email matched no
     * existing user. THAT is the only email resolution performed: the raw
     * submitted email string is never read, never stored, and never queried
     * with, so an attacker cannot write arbitrary text (or a payload aimed at
     * whoever later reads the audit log) into this table by typing it into
     * the login form. When no user matched, the event still records the
     * attempt — with real IP/user-agent/route metadata — against a generic
     * unidentified subject carrying `identified: false`.
     *
     * The actor is always AuditActorContext::guest(): a failed attempt proves
     * only that someone typed an email, never that the owner of that account
     * was the one typing, so attributing the attempt to them as the ACTOR
     * would be a fabricated identity claim. The matched account is recorded
     * as the SUBJECT instead, which is what actually happened.
     */
    public function loginFailed(?User $user): void
    {
        if (! $this->claim('login_failed')) {
            return;
        }

        $this->record(
            action: 'login_failed',
            status: AuditStatus::Failure,
            actor: $this->actorResolver->forGuest(),
            subjectKey: $user?->getKey(),
            subjectLabel: $user !== null ? UserSecuritySnapshot::label($user) : null,
            newValues: $user !== null
                ? [
                    'identified' => true,
                    'user_id' => $user->getKey(),
                    'email' => $user->email,
                    'is_active' => (bool) $user->is_active,
                ]
                : ['identified' => false],
        );
    }

    /**
     * $user is the snapshot SessionGuard captured BEFORE it cleared the
     * session (see Illuminate\Auth\SessionGuard::logout(), which reads
     * $this->user() first and only nulls it after dispatching `Logout`), so
     * the account is still fully readable here. Never de-duplicated: two
     * logouts really are two events, and there is no duplicate-dispatch path
     * for this one.
     */
    public function loggedOut(User $user): void
    {
        $this->record(
            action: 'logout',
            status: AuditStatus::Success,
            actor: $this->actorResolver->forUser($user),
            subjectKey: $user->getKey(),
            subjectLabel: UserSecuritySnapshot::label($user),
            newValues: ['user_id' => $user->getKey()],
        );
    }

    private function claim(string $action): bool
    {
        if (isset($this->recordedThisAttempt[$action])) {
            return false;
        }

        $this->recordedThisAttempt[$action] = true;

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $newValues
     */
    private function record(
        string $action,
        AuditStatus $status,
        AuditActorContext $actor,
        int|string|null $subjectKey,
        ?string $subjectLabel,
        ?array $newValues,
    ): void {
        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: SecurityAuditRecorder::EVENT_CATEGORY,
                eventAction: $action,
                actor: $actor,
                status: $status,
                subjectType: SecurityAuditSubject::Authentication->value,
                subjectKey: $subjectKey === null ? null : (string) $subjectKey,
                subjectLabel: $subjectLabel,
                newValues: $newValues,
            ),
            AuditFailureMode::BestEffort,
        );
    }
}
