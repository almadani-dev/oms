<?php

namespace App\Services\Audit;

use App\Enums\AuditActorType;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Immutable snapshot of "who/what" performed an audited action, resolved
 * once at the call site and handed to AuditLogger — never re-derived inside
 * the logger itself. There is deliberately no constructor/factory that
 * accepts a raw actor name/email string: the only way to populate
 * actor_name/actor_email/actor_roles is to pass a real, already-hydrated
 * User model (forUser()), which is how OMS Task 9A §K's login-failure rule
 * ("a submitted email may only be stored when it resolves to an existing
 * User") is enforced structurally rather than by convention — a future
 * 9B.4 login-failure listener must resolve the User first and pass it here,
 * or call guest() and store no email at all.
 *
 * IP/user-agent/route/method are only ever populated when a real HTTP
 * Request is supplied — every non-interactive factory (system/scheduler/
 * queue/command) leaves them null, never fabricated.
 */
final class AuditActorContext
{
    /**
     * Defensive cap on the role-name snapshot — this is a display/filter
     * aid, never a permission dump (see class docblock and
     * AuditLogger — permissions are never queried here at all).
     */
    private const MAX_ROLES = 20;

    private const MAX_ROLE_NAME_LENGTH = 100;

    private function __construct(
        public readonly AuditActorType $actorType,
        public readonly ?int $actorUserId,
        public readonly ?string $actorName,
        public readonly ?string $actorEmail,
        public readonly array $actorRoles,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly ?string $routeName,
        public readonly ?string $httpMethod,
    ) {}

    /**
     * An authenticated, interactive actor. $request is optional so this can
     * also represent a user-initiated action observed outside an HTTP
     * request (e.g. a queued job resolving the snapshot it was handed at
     * dispatch time via queue()/command() instead) — pass it whenever a
     * real request genuinely exists.
     */
    public static function forUser(User $user, ?Request $request = null): self
    {
        return new self(
            actorType: AuditActorType::User,
            actorUserId: $user->getKey(),
            actorName: $user->name,
            actorEmail: $user->email,
            actorRoles: self::boundRoles($user->getRoleNames()->all()),
            ipAddress: $request?->ip(),
            userAgent: self::boundUserAgent($request?->userAgent()),
            routeName: $request?->route()?->getName(),
            httpMethod: $request?->method(),
        );
    }

    /**
     * An interactive request with no resolved identity — e.g. a failed
     * login attempt whose submitted email did not match a real user. IP/
     * user-agent/route are still real request metadata; actor identity
     * fields stay null. There is no overload that accepts a raw email
     * string here by design (see class docblock).
     */
    public static function guest(?Request $request = null): self
    {
        return new self(
            actorType: AuditActorType::User,
            actorUserId: null,
            actorName: null,
            actorEmail: null,
            actorRoles: [],
            ipAddress: $request?->ip(),
            userAgent: self::boundUserAgent($request?->userAgent()),
            routeName: $request?->route()?->getName(),
            httpMethod: $request?->method(),
        );
    }

    public static function system(): self
    {
        return self::nonInteractive(AuditActorType::System);
    }

    public static function scheduler(): self
    {
        return self::nonInteractive(AuditActorType::Scheduler);
    }

    /**
     * A queued job. $initiatingUser lets a caller thread through the
     * identity of whoever dispatched the job (resolved at dispatch time,
     * where a real auth() context exists) — never re-derived inside the
     * worker, where auth() is meaningless. Never populates IP/user-agent/
     * route regardless of $initiatingUser being supplied.
     */
    public static function queue(?User $initiatingUser = null): self
    {
        return self::nonInteractive(AuditActorType::Queue, $initiatingUser);
    }

    /**
     * An Artisan command run from a terminal — no HTTP context can ever
     * exist here. $initiatingUser is for the rare command that already
     * knows which user requested the underlying operation (e.g. a future
     * `oms:restore` correlating back to the user who requested the
     * restore) — most command-actor events will simply omit it.
     */
    public static function command(?User $initiatingUser = null): self
    {
        return self::nonInteractive(AuditActorType::Command, $initiatingUser);
    }

    private static function nonInteractive(AuditActorType $type, ?User $initiatingUser = null): self
    {
        return new self(
            actorType: $type,
            actorUserId: $initiatingUser?->getKey(),
            actorName: $initiatingUser?->name,
            actorEmail: $initiatingUser?->email,
            actorRoles: $initiatingUser !== null ? self::boundRoles($initiatingUser->getRoleNames()->all()) : [],
            ipAddress: null,
            userAgent: null,
            routeName: null,
            httpMethod: null,
        );
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private static function boundRoles(array $roles): array
    {
        return array_values(array_map(
            static fn (string $role): string => mb_substr($role, 0, self::MAX_ROLE_NAME_LENGTH),
            array_slice($roles, 0, self::MAX_ROLES),
        ));
    }

    private static function boundUserAgent(?string $userAgent): ?string
    {
        return $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
    }
}
