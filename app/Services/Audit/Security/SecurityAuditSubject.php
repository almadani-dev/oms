<?php

namespace App\Services\Audit\Security;

/**
 * The closed set of stable `subject_type` aliases for `event_category =
 * security` (OMS Task 9B.4).
 *
 * Exactly like App\Services\Audit\Financial\FinancialAuditSubject and unlike
 * App\Services\Audit\Crud\AuditSubjectRegistry, this is keyed by the LOGICAL
 * security subject, never by a model class — which is what structurally
 * guarantees `subject_type` can never hold a PHP FQCN. Two of these have no
 * single owning class at all:
 *
 *  - `authentication` describes a login/logout attempt, which is an event in
 *    Laravel's auth guard, not a row in any table;
 *  - `permission_sync` describes one run of the permission-synchronisation
 *    command/action, which mutates `permissions`, `roles` and
 *    `role_has_permissions` together and is meaningless as any one of them.
 *
 * `permission` is registered for completeness of the phase's vocabulary and
 * is deliberately unused by any writer today: PermissionResource is
 * structurally read-only (see PermissionPolicy/PermissionResource) and the
 * only code that creates a Permission row is PermissionSyncService, whose
 * whole run is audited once as `permission_sync`. Emitting a per-permission
 * `permission` event would be exactly the row-level duplication this phase
 * forbids.
 */
enum SecurityAuditSubject: string
{
    case User = 'user';
    case Role = 'role';
    case Permission = 'permission';
    case Authentication = 'authentication';
    case PermissionSync = 'permission_sync';
}
