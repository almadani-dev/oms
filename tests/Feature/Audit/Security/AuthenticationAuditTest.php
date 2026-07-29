<?php

namespace Tests\Feature\Audit\Security;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * OMS Task 9B.4 — the three BEST-EFFORT authentication events, exercised
 * through the REAL login page (Filament\Auth\Pages\Login, the page
 * AdminPanelProvider's ->login() registers) and the REAL logout route, never
 * by dispatching the events by hand.
 */
class AuthenticationAuditTest extends SecurityAuditTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    private function account(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'email' => 'admin@example.test',
            'password' => self::PASSWORD,
        ]);
    }

    private function attemptLogin(string $email, string $password): void
    {
        Livewire::test(Login::class)
            ->fillForm(['email' => $email, 'password' => $password])
            ->call('authenticate');
    }

    // ---- login success ---------------------------------------------------

    public function test_a_successful_login_is_recorded_once(): void
    {
        $user = $this->account(['name' => 'مدير النظام']);

        $this->attemptLogin($user->email, self::PASSWORD);

        $this->assertAuthenticatedAs($user);

        $event = $this->onlySecurityEvent('authentication', 'login_success');

        $this->assertSame('security', $event->event_category);
        $this->assertSame(AuditStatus::Success, $event->status);
        $this->assertSame((string) $user->id, $event->subject_key);
        $this->assertSame('مدير النظام — admin@example.test', $event->subject_label);

        // Actor AND subject both point at the authenticated user.
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($user->email, $event->actor_email);
        $this->assertSame(AuditActorType::User, $event->actor_type);
        $this->assertSame($user->id, $event->new_values['user_id']);
    }

    public function test_a_successful_login_records_real_request_metadata(): void
    {
        $user = $this->account();

        $this->attemptLogin($user->email, self::PASSWORD);

        $event = $this->onlySecurityEvent('authentication', 'login_success');

        $this->assertNotNull($event->ip_address);
        $this->assertNotNull($event->http_method);
        $this->assertNotNull($event->route_name);
    }

    public function test_a_successful_login_records_the_users_roles(): void
    {
        $user = $this->account();
        $this->role(PermissionRegistry::SUPER_ADMIN);
        $user->syncRoles([PermissionRegistry::SUPER_ADMIN]);

        $this->attemptLogin($user->email, self::PASSWORD);

        $event = $this->onlySecurityEvent('authentication', 'login_success');

        $this->assertSame([PermissionRegistry::SUPER_ADMIN], $event->new_values['roles']);
        $this->assertSame([PermissionRegistry::SUPER_ADMIN], $event->actor_roles);
    }

    // ---- login failure ---------------------------------------------------

    public function test_a_failed_login_for_an_existing_user_records_the_known_user_snapshot(): void
    {
        $user = $this->account(['name' => 'صاحب الحساب']);

        $this->attemptLogin($user->email, 'the-wrong-password');

        $this->assertGuest();

        $event = $this->onlySecurityEvent('authentication', 'login_failed');

        $this->assertSame(AuditStatus::Failure, $event->status);
        $this->assertSame((string) $user->id, $event->subject_key);
        $this->assertSame('صاحب الحساب — admin@example.test', $event->subject_label);
        $this->assertSame([
            'identified' => true,
            'user_id' => $user->id,
            'email' => $user->email,
            'is_active' => true,
        ], $event->new_values);
    }

    /**
     * A failed attempt proves someone TYPED an email, never that the owner of
     * that account was typing — so the account is the SUBJECT, and the actor
     * identity stays deliberately empty while real request metadata is kept.
     */
    public function test_a_failed_login_never_claims_the_matched_user_as_the_actor(): void
    {
        $user = $this->account();

        $this->attemptLogin($user->email, 'the-wrong-password');

        $event = $this->onlySecurityEvent('authentication', 'login_failed');

        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->actor_name);
        $this->assertNull($event->actor_email);
        $this->assertSame([], $event->actor_roles);
        $this->assertNotNull($event->ip_address);
    }

    public function test_a_failed_login_for_an_unknown_email_persists_no_attacker_controlled_text(): void
    {
        $this->account();

        // A well-formed but non-existent address: it has to pass the login
        // form's own email() rule to reach the auth guard at all, so this is
        // the realistic shape of an enumeration attempt.
        $attackerEmail = 'ghost-probe@attacker-controlled.test';

        $this->attemptLogin($attackerEmail, 'anything-at-all');

        $event = $this->onlySecurityEvent('authentication', 'login_failed');

        $this->assertNull($event->subject_key);
        $this->assertNull($event->subject_label);
        $this->assertSame(['identified' => false], $event->new_values);

        $this->assertEventContainsNone($event, [
            $attackerEmail,
            'ghost-probe',
            'attacker-controlled.test',
            'anything-at-all',
        ]);
    }

    public function test_a_failed_login_never_stores_the_submitted_password(): void
    {
        $user = $this->account();

        $this->attemptLogin($user->email, 'a-very-distinctive-wrong-password');

        $this->assertEventContainsNone(
            $this->onlySecurityEvent('authentication', 'login_failed'),
            ['a-very-distinctive-wrong-password', self::PASSWORD, '$2y$'],
        );
    }

    public function test_a_successful_login_never_stores_the_submitted_password(): void
    {
        $user = $this->account();

        $this->attemptLogin($user->email, self::PASSWORD);

        $this->assertEventContainsNone(
            $this->onlySecurityEvent('authentication', 'login_success'),
            [self::PASSWORD, $user->fresh()->password, '$2y$', 'remember_token'],
        );
    }

    /**
     * THE duplicate-prevention case. When credentials are valid but
     * canAccessPanel() denies entry, Illuminate\Auth\SessionGuard::
     * attemptWhen() fires `Failed` AND Filament\Auth\Pages\Login fires it a
     * second time. That is ONE logical attempt and must be ONE event.
     */
    public function test_a_panel_access_denial_records_exactly_one_failed_login_event(): void
    {
        $user = $this->account(['is_active' => false]);

        $this->attemptLogin($user->email, self::PASSWORD);

        $this->assertGuest();

        $event = $this->onlySecurityEvent('authentication', 'login_failed');

        $this->assertSame((string) $user->id, $event->subject_key);
        $this->assertFalse($event->new_values['is_active']);
    }

    public function test_two_separate_failed_attempts_record_two_events(): void
    {
        $user = $this->account();

        $this->attemptLogin($user->email, 'wrong-one');
        $this->attemptLogin($user->email, 'wrong-two');

        $this->assertCount(2, $this->securityEvents('authentication', 'login_failed'));
    }

    // ---- logout ----------------------------------------------------------

    public function test_a_logout_is_recorded_once(): void
    {
        $user = $this->account(['name' => 'خارج']);

        $this->actingAs($user)->post('/admin/logout');

        $this->assertGuest();

        $event = $this->onlySecurityEvent('authentication', 'logout');

        $this->assertSame(AuditStatus::Success, $event->status);
        $this->assertSame((string) $user->id, $event->subject_key);
        $this->assertSame('خارج — admin@example.test', $event->subject_label);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($user->id, $event->new_values['user_id']);
    }

    /**
     * The user snapshot survives even though SessionGuard has already cleared
     * the session by the time `Logout` is dispatched.
     */
    public function test_a_logout_preserves_the_user_snapshot_taken_before_the_session_was_cleared(): void
    {
        $user = $this->account(['name' => 'مستخدم']);

        $this->actingAs($user)->post('/admin/logout');

        $event = $this->onlySecurityEvent('authentication', 'logout');

        $this->assertSame($user->email, $event->actor_email);
        $this->assertNotNull($event->subject_label);
    }

    public function test_login_then_logout_records_exactly_two_events(): void
    {
        $user = $this->account();

        $this->attemptLogin($user->email, self::PASSWORD);
        $this->post('/admin/logout');

        $this->assertCount(1, $this->securityEvents('authentication', 'login_success'));
        $this->assertCount(1, $this->securityEvents('authentication', 'logout'));
        $this->assertCount(2, $this->securityEvents('authentication'));
    }

    /**
     * `Authenticated` fires on EVERY authenticated request. It is deliberately
     * not subscribed, so browsing the panel must add no audit rows at all.
     */
    public function test_ordinary_authenticated_requests_write_no_authentication_events(): void
    {
        $user = $this->account();

        $this->actingAs($user);
        $this->get('/admin')->assertSuccessful();
        $this->get('/admin')->assertSuccessful();

        $this->assertCount(0, $this->securityEvents('authentication'));
    }

    // ---- best-effort: never break the flow -------------------------------

    /**
     * An audit-storage outage must never stop a legitimate login — the
     * alternative is a login loop that locks out the very administrators who
     * would have to repair the audit storage.
     */
    public function test_an_audit_failure_does_not_prevent_a_login(): void
    {
        $user = $this->account();

        Schema::drop('audit_events');

        $this->attemptLogin($user->email, self::PASSWORD);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * ...and must never stop a legitimate logout, which happens AFTER the
     * session has already been destroyed and cannot be rolled back anyway.
     */
    public function test_an_audit_failure_does_not_prevent_a_logout(): void
    {
        $user = $this->account();
        $this->actingAs($user);

        Schema::drop('audit_events');

        $this->post('/admin/logout');

        $this->assertGuest();
    }

    public function test_an_audit_failure_does_not_change_a_failed_logins_outcome(): void
    {
        $user = $this->account();

        Schema::drop('audit_events');

        $this->attemptLogin($user->email, 'still-wrong');

        $this->assertGuest();
    }

    /**
     * Regression guard for a real bug found while building this phase:
     * Laravel's framework EventServiceProvider auto-discovers public
     * `handle*` methods in app/Listeners and registers them as listeners on
     * top of any explicit registration. Naming the subscriber's methods
     * `handleLogin`/`handleLogout` therefore registered every one of them
     * TWICE and wrote two identical rows per login and per logout. If a
     * future rename reintroduces `handle*` names, this fails immediately —
     * long before the duplicate rows are noticed in production.
     */
    public function test_each_authentication_event_has_exactly_one_registered_listener(): void
    {
        $raw = \Illuminate\Support\Facades\Event::getRawListeners();

        foreach ([
            \Illuminate\Auth\Events\Attempting::class,
            \Illuminate\Auth\Events\Login::class,
            \Illuminate\Auth\Events\Failed::class,
            \Illuminate\Auth\Events\Logout::class,
        ] as $event) {
            $this->assertCount(
                1,
                $raw[$event] ?? [],
                sprintf('%s must have exactly one audit listener registered.', class_basename($event)),
            );
        }
    }

    public function test_every_authentication_event_uses_the_stable_alias(): void
    {
        $user = $this->account();

        $this->attemptLogin($user->email, 'wrong');
        $this->attemptLogin($user->email, self::PASSWORD);
        $this->post('/admin/logout');

        $this->assertSame(3, AuditEvent::count());

        foreach (AuditEvent::all() as $event) {
            $this->assertSame('security', $event->event_category);
            $this->assertSame('authentication', $event->subject_type);
        }
    }
}
