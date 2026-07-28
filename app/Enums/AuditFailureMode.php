<?php

namespace App\Enums;

/**
 * Controls AuditLogger::record()'s behavior when the audit insert itself
 * fails. Not a database column — a per-call parameter only.
 *
 * Required: persistence failure propagates (AuditPersistenceException).
 * Callers auditing a state-changing financial/security action are expected
 * to call this inside their own existing DB::transaction() so the business
 * mutation and the audit row commit — or roll back — together.
 *
 * BestEffort: persistence failure is caught, logged sanitized/bounded via
 * Laravel's normal error log, and record() returns null. Used for events
 * that must never block or roll back the action they describe (e.g. a
 * login failure, a logout, or anything already past an irreversible
 * boundary).
 */
enum AuditFailureMode
{
    case Required;
    case BestEffort;
}
