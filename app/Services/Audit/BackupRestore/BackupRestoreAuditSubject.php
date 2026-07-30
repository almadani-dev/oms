<?php

namespace App\Services\Audit\BackupRestore;

/**
 * The closed subject vocabulary for `event_category = backup_restore`
 * (OMS Task 9B.6). Values are stable aliases persisted in
 * `audit_events.subject_type` — never a PHP FQCN, exactly like
 * App\Services\Audit\Crud\AuditSubjectRegistry's aliases (a class rename must
 * never orphan historical rows).
 *
 * WHY ONLY TWO CASES. The Task 9B.6 scope offers three candidate aliases —
 * `backup`, `restore`, `backup_operation` — and requires the NARROWEST
 * accurate one. Every event this phase can emit is unambiguously about either
 * a backup archive's lifecycle (creation/download/deletion) or a restore
 * operation's lifecycle, so the broader `backup_operation` alias (which would
 * only describe "a row in the shared backup_operations table", the storage
 * detail both share — see App\Models\BackupOperation) is deliberately never
 * used and therefore deliberately not defined. If a future phase ever audits
 * something about that shared table itself rather than about a backup or a
 * restore, it should be ADDED here then, not anticipated now.
 */
enum BackupRestoreAuditSubject: string
{
    /**
     * The single event category for this whole phase. Declared here, next to
     * the subject vocabulary, so the category and the aliases that may appear
     * under it stay one closed unit rather than two literals maintained in
     * separate recorders.
     */
    public const EVENT_CATEGORY = 'backup_restore';

    /** A backup archive and its creation/download/deletion lifecycle. */
    case Backup = 'backup';

    /** A restore operation and its request/execution/outcome lifecycle. */
    case Restore = 'restore';
}
