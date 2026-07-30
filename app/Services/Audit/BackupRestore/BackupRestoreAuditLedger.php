<?php

namespace App\Services\Audit\BackupRestore;

use App\Models\AuditEvent;

/**
 * The one deterministic "has this exact lifecycle state already been recorded
 * for this exact operation?" check both backup_restore recorders use
 * (OMS Task 9B.6 §8).
 *
 * WHY A PRE-INSERT CHECK AND NOT A DATABASE CONSTRAINT. A partial unique index
 * on (correlation_id, event_action) would be the stronger guarantee, but
 * `audit_events` is an append-only table shared by every audit category (see
 * the audit_events migration) and this phase adds no migration: a unique
 * constraint scoped that way would also forbid legitimately repeatable events
 * in other categories that reuse a correlation id. The check is instead made
 * safe by WHERE it runs — every lifecycle state this guards is written from
 * inside a path already serialized by the backup/restore subsystem's own
 * mutual exclusion (BackupSubsystemLock, the per-backup Cache lock, the
 * atomic launch claim, or the single detached restore process), so there is no
 * concurrent writer for the same correlation id to race with.
 *
 * `correlation_id` is indexed (audit_events_correlation_id_idx), so this costs
 * one indexed existence probe.
 *
 * Deliberately scoped to `event_category = backup_restore` as well: a future
 * category that happens to reuse a backup/restore correlation id must never
 * make this report "already recorded" for a state it knows nothing about.
 */
final class BackupRestoreAuditLedger
{
    public function recorded(string $correlationId, string $action): bool
    {
        return AuditEvent::query()
            ->where('correlation_id', $correlationId)
            ->where('event_category', BackupRestoreAuditSubject::EVENT_CATEGORY)
            ->where('event_action', $action)
            ->exists();
    }
}
