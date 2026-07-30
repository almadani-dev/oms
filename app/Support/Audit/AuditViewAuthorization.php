<?php

namespace App\Support\Audit;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The single "is this actor a REAL Super Admin" check for the read-only Audit
 * Log UI (OMS Task 9B.7).
 *
 * Deliberately role-only, unlike App\Support\Backup\BackupAuthorization: Task
 * 9B.7 adds NO `audit.*` permission to PermissionRegistry, so there is no
 * second factor to require and — more importantly — no permission that a
 * future role edit could accidentally grant to a lesser role. The role name
 * is compared through the same PermissionRegistry::SUPER_ADMIN constant the
 * Gate::before bypass itself uses (see AppServiceProvider), so the two can
 * never drift apart.
 *
 * This is plain PHP: it never calls Gate/`can()`, so it is never
 * short-circuited by the Super-Admin Gate::before bypass and never widened by
 * a broad permission grant. AuditEventResource's canViewAny()/canView()
 * delegate here instead of to a Policy (there is no AuditEventPolicy, and one
 * would be bypassed by Gate::before for exactly the actor this resource is
 * for, making the denial half of it meaningless).
 */
final class AuditViewAuthorization
{
    public static function check(?Authenticatable $user): bool
    {
        return $user instanceof User
            && $user->hasRole(PermissionRegistry::SUPER_ADMIN);
    }

    /**
     * @throws HttpException (403)
     */
    public static function authorize(?Authenticatable $user): void
    {
        abort_unless(self::check($user), 403);
    }
}
