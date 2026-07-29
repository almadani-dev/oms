<?php

namespace App\Listeners\Auth;

use App\Models\User;
use App\Services\Audit\Security\AuthenticationAuditRecorder;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;

/**
 * The complete, deliberately minimal mapping from Laravel's authentication
 * events to OMS's audit trail (OMS Task 9B.4). Registered in
 * AppServiceProvider::boot().
 *
 * FOUR events are subscribed, and no others:
 *
 *  - `Attempting` — writes NOTHING. It only opens a fresh de-duplication
 *    window on the recorder (see AuthenticationAuditRecorder's docblock for
 *    why one attempt, not one request, is the correct window). It is the one
 *    event here whose payload carries the plaintext password, in
 *    `$event->credentials['password']`; this listener never touches
 *    `$event->credentials` at all, which is why the parameter is not even
 *    accepted.
 *  - `Login` → `login_success`.
 *  - `Failed` → `login_failed`, using ONLY `$event->user` — the account
 *    Laravel's own user provider already resolved. `$event->credentials` is
 *    never read here either, so neither the submitted password nor the raw
 *    submitted email can reach the audit log.
 *  - `Logout` → `logout`.
 *
 * NOT subscribed, on purpose:
 *
 *  - `Authenticated` fires on EVERY authenticated request when the session
 *    guard resolves the user. Auditing it would write one row per page view
 *    and drown the real security trail.
 *  - `Validated` fires mid-attempt, before the panel-access check has run, so
 *    it does not yet mean a login happened.
 *  - `CurrentDeviceLogout`/`OtherDeviceLogout` have no trigger anywhere in
 *    this application (verified: nothing calls logoutCurrentDevice() or
 *    logoutOtherDevices()). They are left unsubscribed rather than wired
 *    speculatively.
 *  - `PasswordReset` has no trigger either: AdminPanelProvider calls
 *    ->login() but never ->passwordReset(), so this application has no
 *    password-reset flow at all. An administrator changing a user's password
 *    goes through UserManagementService and is audited there as part of the
 *    single `security.updated` event on subject `user`.
 *
 * Every handler is a plain synchronous listener — never ShouldQueue. A queued
 * listener would serialize the event (and, for `Failed`, its credentials
 * array) into the jobs table, and would resolve the actor's request metadata
 * in a worker where no request exists.
 *
 * NAMING IS LOAD-BEARING: the methods below are deliberately NOT called
 * `handle*` or `__invoke`. Laravel's framework-level EventServiceProvider
 * auto-discovers listeners by scanning `app/Listeners` for public methods
 * matching `handle*`/`__invoke` and registering each as `Class@method` for
 * its first parameter's type (Illuminate\Events\DiscoverEvents). With
 * `handle*` names, every event here would be registered TWICE — once by
 * discovery and once by the explicit subscribe() mapping below — and each
 * login and logout would write two identical audit rows. The explicit mapping
 * is kept (it is the readable, greppable record of exactly what is audited);
 * the names simply stay outside discovery's pattern so it remains the only
 * registration. Renaming any method to `handleX` reintroduces the duplicate.
 */
class AuthenticationAuditSubscriber
{
    public function __construct(private readonly AuthenticationAuditRecorder $recorder) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            Attempting::class => 'onAttempting',
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Logout::class => 'onLogout',
        ];
    }

    public function onAttempting(Attempting $event): void
    {
        $this->recorder->beginAttempt();
    }

    public function onLogin(Login $event): void
    {
        $user = $this->asUser($event->user);

        if ($user === null) {
            return;
        }

        $this->recorder->loginSucceeded($user);
    }

    public function onFailed(Failed $event): void
    {
        $this->recorder->loginFailed($this->asUser($event->user));
    }

    /**
     * `Logout` can arrive with a null user — SessionGuard::logout() reads
     * $this->user() first, which is null if the session had already expired
     * or was cleared by something else. There is no subject to record then,
     * so nothing is written rather than an event about nobody.
     */
    public function onLogout(Logout $event): void
    {
        $user = $this->asUser($event->user);

        if ($user === null) {
            return;
        }

        $this->recorder->loggedOut($user);
    }

    /**
     * Guards against a non-App\Models\User Authenticatable reaching the
     * recorder — the audit layer's user snapshot and actor context are both
     * typed against the real model, and no other authenticatable exists in
     * this application (config('auth.providers.users.model')).
     */
    private function asUser(?Authenticatable $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
