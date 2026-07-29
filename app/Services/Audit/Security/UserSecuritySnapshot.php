<?php

namespace App\Services\Audit\Security;

use App\Models\User;

/**
 * The bounded, redaction-safe snapshot of one User for a `security` audit
 * event (OMS Task 9B.4), and the single definition of which User fields may
 * ever reach the audit log.
 *
 * This is a CLOSED ALLOWLIST, not a filtered `$user->toArray()`: the six
 * fields below are the only ones ever read. `password`, `remember_token`,
 * `email_verified_at`, the session id, any reset token and every request
 * cookie/header are not omitted by a denylist that could be out-argued — they
 * are never looked at. (App\Services\Audit\AuditRedactor still runs over the
 * result afterwards as an independent second layer; see
 * SecurityAuditRecorderTest, which asserts a hash can never appear.)
 *
 * Role names and direct permission names are read through the RELATION QUERY
 * BUILDERS (`roles()`/`permissions()`), never through the loaded relations or
 * Spatie's permission cache. A "before" snapshot taken after `fresh()` and an
 * "after" snapshot taken after `syncRoles()` must reflect two genuinely
 * different database states in the same request, and an already-hydrated
 * relation would hand back the pre-sync collection for both.
 *
 * `permissions()` is Spatie's DIRECT-permission relation only — role-derived
 * permissions are deliberately excluded. Storing a user's full effective
 * permission set would be the "full permission dump" OMS Task 9A §K forbids,
 * would balloon a single event past AuditPayloadBounder's 8 KB cap, and would
 * duplicate information already implied by the role names on the same row.
 */
final class UserSecuritySnapshot
{
    /**
     * @return array{
     *     user_id: int|null,
     *     name: string|null,
     *     email: string|null,
     *     is_active: bool,
     *     roles: list<string>,
     *     direct_permissions: list<string>,
     * }
     */
    public static function of(User $user): array
    {
        return [
            'user_id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'roles' => self::roleNames($user),
            'direct_permissions' => self::directPermissionNames($user),
        ];
    }

    /**
     * @return list<string>
     */
    public static function roleNames(User $user): array
    {
        return SecurityNameDiff::normalize($user->roles()->pluck('name')->all());
    }

    /**
     * @return list<string>
     */
    public static function directPermissionNames(User $user): array
    {
        return SecurityNameDiff::normalize($user->permissions()->pluck('name')->all());
    }

    /**
     * The human-readable `subject_label` snapshot. Both parts are already
     * allowlisted payload fields on the same row, so this leaks nothing new;
     * it exists so a deleted user's event stays readable after the row itself
     * is gone.
     */
    public static function label(User $user): ?string
    {
        $parts = array_values(array_filter(
            [trim((string) $user->name), trim((string) $user->email)],
            static fn (string $part): bool => $part !== '',
        ));

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
