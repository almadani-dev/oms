<?php

namespace App\Support\Backup;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Single shared "real Super Admin AND holds this specific backups.*
 * permission" check for the whole Task 7B.2 UI (page access, every header/
 * row action, and BackupDeletionService's own boundary) — mirrors the exact
 * two-layer pattern BackupDownloadController already uses (see its
 * docblock): Gate::before grants a real Super Admin every ability
 * automatically, so checking the permission alone would not by itself
 * guarantee "Super Admin only" if that permission were ever manually
 * granted to a lesser role. Never itself renders a response — callers
 * decide (a 403 abort, hiding a button, or throwing a domain exception).
 */
final class BackupAuthorization
{
    public static function check(?Authenticatable $user, string $permission): bool
    {
        return $user instanceof User
            && $user->hasRole(PermissionRegistry::SUPER_ADMIN)
            && $user->can($permission);
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException (403)
     */
    public static function authorize(?Authenticatable $user, string $permission): void
    {
        abort_unless(self::check($user, $permission), 403);
    }
}
