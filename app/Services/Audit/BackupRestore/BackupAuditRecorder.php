<?php

namespace App\Services\Audit\BackupRestore;

use App\Enums\AuditActorType;
use App\Enums\AuditFailureMode;
use App\Enums\AuditStatus;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Audit\AuditActorContext;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;
use App\Services\Backup\SecretstreamEnvelope;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The single write path for every BACKUP-side event on
 * `event_category = backup_restore` (OMS Task 9B.6). Restore-side events have
 * their own recorder (RestoreAuditRecorder) — neither can emit the other's
 * events.
 *
 * ---------------------------------------------------------------------------
 * ONE LIFECYCLE STATE, ONE EMITTER — WHY THESE CALL SITES AND NO OTHERS.
 * ---------------------------------------------------------------------------
 * `backup_requested` is emitted from exactly ONE place:
 * BackupCreationOrchestrator::enqueue(), the single funnel through which every
 * backup in this application is created — the Filament "إنشاء نسخة احتياطية"
 * action, the `oms:backup` command (whether run by hand or by the two
 * scheduler entries in bootstrap/app.php), and RestoreOrchestrator's mandatory
 * pre-restore safety backup all call it and nothing else creates a
 * BackupOperation row. That is what structurally prevents the "Filament action
 * plus command", "scheduler plus command", and "command plus orchestrator"
 * duplication this phase warns about: the duplication cannot occur because the
 * outer layers record nothing at all.
 *
 * `backup_completed`/`backup_failed` are emitted from the creation pipeline
 * itself (BackupCreationOrchestrator::execute()) plus CreateBackupJob::failed()
 * as the queue-level safety net for the case where the orchestrator never ran.
 * Because two emitters exist for `backup_failed`, and because CreateBackupJob
 * retries (tries=3), every terminal state is guarded by
 * BackupRestoreAuditLedger: at most one row per (backup uuid, action).
 *
 * ---------------------------------------------------------------------------
 * REQUIRED vs BEST-EFFORT — WHY COMPLETION IS NOT "REQUIRED".
 * ---------------------------------------------------------------------------
 * `backup_requested` and `backup_delete_requested` are REQUIRED: both are
 * recorded BEFORE the thing they describe becomes real (before the queued row
 * exists / before the archive is unlinked), so a failure to record safely
 * prevents the operation instead of leaving an unaccounted-for archive or an
 * unaccounted-for deletion. `backup_downloaded` is REQUIRED for the same
 * reason attachment reads are (see AttachmentAccessAuditRecorder): a private
 * encrypted backup archive that cannot be accounted for is not served.
 *
 * `backup_completed`, `backup_failed` and `backup_deleted` are BEST-EFFORT,
 * and that is a deliberate, load-bearing choice rather than a convenience:
 * both describe state that has ALREADY irreversibly happened on the
 * filesystem. By the time completion is recorded, the verified archive has
 * already been published under its final name; the creation pipeline's own
 * catch block would react to a thrown AuditPersistenceException by marking the
 * operation `failed` while leaving that perfectly valid published archive in
 * place — i.e. an audit outage would turn a good backup into a row that lies
 * about it. Task 9B.6 §6 forbids exactly that ("do not delete a valid
 * completed backup merely because a completion audit insert fails", and never
 * claim a rollback that did not happen). The same applies to `backup_deleted`:
 * the archive is already unlinked, so failing there could only produce a false
 * "still present" record. A BestEffort failure is still never silent —
 * AuditLogger logs it with a sanitized fingerprint.
 *
 * ---------------------------------------------------------------------------
 * ACTOR POLICY (§9).
 * ---------------------------------------------------------------------------
 * requestActor(): a real authenticated interactive request wins (the Filament
 * manual action) -> actor_type `user` with genuine IP/route metadata. Failing
 * that, a `daily`/`weekly` type means the request came from the schedule
 * (BackupType::isScheduled() — the two bootstrap/app.php entries are the only
 * producers of those types) -> actor_type `scheduler`, with NO invented user.
 * Otherwise -> actor_type `command`, carrying the initiating user only when
 * the architecture genuinely passes one (`created_by`, which
 * RestoreOrchestrator really does supply for a pre-restore safety backup).
 *
 * executionActor(): identical, MINUS the scheduler branch. Completion/failure
 * of a scheduled backup is decided by the queue worker that ran it, not by the
 * scheduler that asked for it, so claiming `scheduler` there would be a
 * factual error about which process observed the outcome.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE PAYLOAD MAY CONTAIN.
 * ---------------------------------------------------------------------------
 * Bounded metadata read off the BackupOperation row only — the same columns the
 * Task 7A audit already established as safe to store there (never the archive's
 * bytes, never a manifest, never a dump). Specifically NEVER: the encryption
 * key or APP_KEY (only `encryption_key_id`, which names which key was used —
 * see BackupKeyRing and AuditRedactor's SAFE_EXCEPTIONS), .env contents,
 * database credentials, SQL, a disk path (not even the row's own relative
 * `stored_path`, and never an absolute or temporary one), process output, a
 * stack trace, or an exception message — failures are reduced to a generic
 * code/category by BackupRestoreFailure, which never reads getMessage() at
 * all.
 *
 * `components` reports only what the real archive can contain: the database
 * dump and the private attachments disk (see BackupManifestBuilder /
 * AttachmentCollector). This application's backup scope has no public-files
 * component, so none is reported rather than invented.
 */
final class BackupAuditRecorder
{
    public const EVENT_CATEGORY = BackupRestoreAuditSubject::EVENT_CATEGORY;

    public const ACTION_REQUESTED = 'backup_requested';

    public const ACTION_COMPLETED = 'backup_completed';

    public const ACTION_FAILED = 'backup_failed';

    public const ACTION_DOWNLOADED = 'backup_downloaded';

    public const ACTION_DOWNLOAD_DENIED = 'backup_download_denied';

    public const ACTION_DELETE_REQUESTED = 'backup_delete_requested';

    public const ACTION_DELETED = 'backup_deleted';

    /** A Super Admin's explicit deletion from the management page. */
    public const TRIGGER_MANUAL = 'manual';

    /** BackupRetentionService's automatic count-window cleanup. */
    public const TRIGGER_RETENTION = 'retention';

    /**
     * Fixed identifier for HOW an archive is encrypted — a scheme name plus the
     * envelope's schema version, never key material and never a configuration
     * value. Mirrors what SecretstreamEnvelope actually implements
     * (libsodium's crypto_secretstream_xchacha20poly1305).
     */
    private const ENCRYPTION_METHOD = 'xchacha20poly1305_secretstream_v'.SecretstreamEnvelope::VERSION;

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AuditActorResolver $actorResolver,
        private readonly BackupRestoreAuditLedger $ledger,
    ) {}

    /**
     * REQUIRED. Called by BackupCreationOrchestrator::enqueue() from inside the
     * same transaction that inserts the row, so the queued backup and its audit
     * event commit — or roll back — together.
     *
     * @throws \App\Services\Audit\Exceptions\AuditPersistenceException
     */
    public function backupRequested(BackupOperation $operation): void
    {
        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: self::ACTION_REQUESTED,
                actor: $this->requestActor($operation),
                subjectType: BackupRestoreAuditSubject::Backup->value,
                subjectKey: $operation->uuid,
                subjectLabel: $this->label($operation),
                newValues: $this->payload($operation),
                reason: $operation->operation_reason,
                correlationId: $operation->uuid,
            ),
            AuditFailureMode::Required,
        );
    }

    /**
     * BEST-EFFORT, at most once per backup. Called only after the archive has
     * been generated, encrypted, fully verified as an unpublished candidate,
     * published under its final name, and the row marked completed+verified —
     * never before, so this event can never claim a success the filesystem
     * does not already show.
     */
    public function backupCompleted(BackupOperation $operation): void
    {
        $this->recordBestEffort(
            $operation,
            self::ACTION_COMPLETED,
            AuditStatus::Success,
            $this->payload($operation),
        );
    }

    /**
     * BEST-EFFORT, at most once per backup — so the orchestrator's own failure
     * handling and CreateBackupJob::failed()'s safety net can never both record
     * the same failure, and a retried attempt that fails again adds nothing new.
     */
    public function backupFailed(BackupOperation $operation, ?Throwable $exception = null, ?string $failureCode = null): void
    {
        $this->recordBestEffort(
            $operation,
            self::ACTION_FAILED,
            AuditStatus::Failure,
            $this->payload($operation, BackupRestoreFailure::payload($exception, $failureCode)),
        );
    }

    /**
     * REQUIRED, and deliberately NOT ledger-guarded: two downloads of the same
     * archive are two genuinely distinct accesses to encrypted financial data,
     * not a duplicated lifecycle state.
     *
     * Called after every authorization and existence/lock check has passed and
     * before the StreamedResponse is built, so an audit-storage failure
     * prevents the bytes from being served (the caller releases its locks and
     * lets AuditPersistenceException propagate).
     *
     * @throws \App\Services\Audit\Exceptions\AuditPersistenceException
     */
    public function backupDownloaded(BackupOperation $operation): void
    {
        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: self::ACTION_DOWNLOADED,
                actor: $this->actorResolver->resolve(),
                subjectType: BackupRestoreAuditSubject::Backup->value,
                subjectKey: $operation->uuid,
                subjectLabel: $this->label($operation),
                newValues: $this->payload($operation),
                correlationId: $operation->uuid,
            ),
            AuditFailureMode::Required,
        );
    }

    /**
     * BEST-EFFORT, and never throws: the caller is already refusing the request
     * with a 403, and letting an audit outage turn that refusal into a 500 would
     * weaken the denial and hand the caller a distinguishable response (the same
     * reasoning as AttachmentAccessAuditRecorder::accessDenied()).
     *
     * Only ever called for a backup UUID that resolves to a real row, so a
     * logged-in prober cannot write one row per guessed identifier — an
     * ordinary 404 (unknown uuid, non-completed, wrong disk, unsafe path,
     * missing file) records nothing at all.
     */
    public function backupDownloadDenied(BackupOperation $operation): void
    {
        try {
            $this->logger->record(
                new AuditRecordRequest(
                    eventCategory: self::EVENT_CATEGORY,
                    eventAction: self::ACTION_DOWNLOAD_DENIED,
                    actor: $this->actorResolver->resolve(),
                    status: AuditStatus::Failure,
                    subjectType: BackupRestoreAuditSubject::Backup->value,
                    subjectKey: $operation->uuid,
                    subjectLabel: $this->label($operation),
                    newValues: $this->payload($operation),
                    correlationId: $operation->uuid,
                ),
                AuditFailureMode::BestEffort,
            );
        } catch (Throwable) {
            // Intentionally swallowed — see the method docblock.
        }
    }

    /**
     * REQUIRED, at most once per backup, recorded BEFORE the archive is
     * unlinked and only after every eligibility rule has already passed.
     *
     * This is the honest half of a non-atomic operation: unlinking a file
     * cannot participate in a database transaction, so the accountable record
     * is "an authorized actor was about to permanently delete this archive",
     * written while refusing to proceed is still possible. The matching
     * `backup_deleted` below then records what actually happened.
     *
     * @param  string  $trigger  self::TRIGGER_MANUAL or self::TRIGGER_RETENTION
     *
     * @throws \App\Services\Audit\Exceptions\AuditPersistenceException
     */
    public function backupDeleteRequested(BackupOperation $operation, string $trigger, ?int $retentionKeepCount = null): void
    {
        if ($this->ledger->recorded($operation->uuid, self::ACTION_DELETE_REQUESTED)) {
            return;
        }

        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: self::ACTION_DELETE_REQUESTED,
                actor: $this->deletionActor($operation, $trigger),
                subjectType: BackupRestoreAuditSubject::Backup->value,
                subjectKey: $operation->uuid,
                subjectLabel: $this->label($operation),
                newValues: $this->payload($operation, $this->retentionSummary($trigger, $retentionKeepCount)),
                correlationId: $operation->uuid,
            ),
            AuditFailureMode::Required,
        );
    }

    /**
     * BEST-EFFORT, at most once per backup. Recorded only after the archive was
     * actually handled and the metadata row soft-deleted — `archive_file_removed`
     * distinguishes "this run unlinked the file" from "the file was already
     * gone", so the event never claims more than it observed.
     *
     * @param  string  $trigger  self::TRIGGER_MANUAL or self::TRIGGER_RETENTION
     */
    public function backupDeleted(BackupOperation $operation, string $trigger, bool $archiveFileRemoved, ?int $retentionKeepCount = null): void
    {
        try {
            // Inside the try on purpose: the ledger probe queries the same
            // audit table that would be failing in the scenario this method
            // must survive, so it can throw exactly like the insert can.
            if ($this->ledger->recorded($operation->uuid, self::ACTION_DELETED)) {
                return;
            }

            $this->logger->record(
                new AuditRecordRequest(
                    eventCategory: self::EVENT_CATEGORY,
                    eventAction: self::ACTION_DELETED,
                    actor: $this->deletionActor($operation, $trigger),
                    subjectType: BackupRestoreAuditSubject::Backup->value,
                    subjectKey: $operation->uuid,
                    subjectLabel: $this->label($operation),
                    newValues: $this->payload($operation, [
                        'archive_file_removed' => $archiveFileRemoved,
                    ] + $this->retentionSummary($trigger, $retentionKeepCount)),
                    correlationId: $operation->uuid,
                ),
                AuditFailureMode::BestEffort,
            );
        } catch (Throwable) {
            // The archive is already gone; nothing here may throw back into a
            // completed irreversible deletion.
        }
    }

    private function recordBestEffort(BackupOperation $operation, string $action, AuditStatus $status, array $payload): void
    {
        try {
            // The ledger probe is INSIDE the try deliberately: it queries the
            // very table whose unavailability this method has to tolerate, so
            // leaving it outside would let a dropped/unreachable audit table
            // throw a QueryException straight into the creation pipeline's own
            // catch block — which would then mark a fully published, verified
            // archive as `failed`. Exactly what §6 forbids.
            if ($this->ledger->recorded($operation->uuid, $action)) {
                return;
            }

            $this->logger->record(
                new AuditRecordRequest(
                    eventCategory: self::EVENT_CATEGORY,
                    eventAction: $action,
                    actor: $this->executionActor($operation),
                    status: $status,
                    subjectType: BackupRestoreAuditSubject::Backup->value,
                    subjectKey: $operation->uuid,
                    subjectLabel: $this->label($operation),
                    newValues: $payload,
                    reason: $operation->operation_reason,
                    correlationId: $operation->uuid,
                ),
                AuditFailureMode::BestEffort,
            );
        } catch (Throwable) {
            // Past the irreversible boundary — see the class docblock. The
            // ledger probe and payload building touch the same database that
            // would already be failing here, so they are covered too.
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(BackupOperation $operation, array $extra = []): array
    {
        return [
            'operation_uuid' => $operation->uuid,
            'backup_type' => $operation->type->value,
            'scope' => $operation->scope->value,
            'status' => $operation->status->value,
            'components' => [
                'database' => $operation->scope->includesDatabase(),
                'private_attachments' => $operation->scope->includesFiles(),
            ],
            'requested_at' => $this->atom($operation->created_at),
            'started_at' => $this->atom($operation->started_at),
            'completed_at' => $this->atom($operation->completed_at),
            'verified_at' => $this->atom($operation->verified_at),
            'failed_at' => $this->atom($operation->failed_at),
            'archive_size_bytes' => $operation->size_bytes,
            'original_size_bytes' => $operation->original_size_bytes,
            'attachment_file_count' => $operation->file_count,
            'checksum_sha256' => $operation->checksum_sha256,
            'manifest_version' => $operation->manifest_version,
            // Which key encrypted the archive, never the key itself. Null until
            // the archive has actually been encrypted, and the method
            // identifier is reported only alongside it so a not-yet-encrypted
            // request never claims an encryption that has not happened.
            'encryption_key_id' => $operation->encryption_key_id,
            'encryption_method' => $operation->encryption_key_id !== null ? self::ENCRYPTION_METHOD : null,
            'is_protected' => (bool) $operation->is_protected,
        ] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    private function retentionSummary(string $trigger, ?int $retentionKeepCount): array
    {
        $summary = ['delete_trigger' => $trigger];

        if ($trigger === self::TRIGGER_RETENTION && $retentionKeepCount !== null) {
            $summary['retention_keep_count'] = $retentionKeepCount;
        }

        return $summary;
    }

    /**
     * A short machine-readable reference, never a filename and never a path.
     */
    private function label(BackupOperation $operation): string
    {
        return sprintf(
            '%s/%s %s',
            $operation->type->value,
            $operation->scope->value,
            substr($operation->uuid, 0, 8),
        );
    }

    private function atom(?Carbon $value): ?string
    {
        return $value?->toIso8601String();
    }

    private function requestActor(BackupOperation $operation): AuditActorContext
    {
        $interactive = $this->interactiveActor();

        if ($interactive !== null) {
            return $interactive;
        }

        if ($operation->type->isScheduled()) {
            return AuditActorContext::scheduler();
        }

        return AuditActorContext::command($this->initiator($operation));
    }

    private function executionActor(BackupOperation $operation): AuditActorContext
    {
        return $this->interactiveActor() ?? AuditActorContext::command($this->initiator($operation));
    }

    /**
     * A manual deletion is always an interactive Super Admin action;
     * retention's is always a non-interactive run with no user behind it (its
     * command/job carries none), so no user is ever invented for it.
     */
    private function deletionActor(BackupOperation $operation, string $trigger): AuditActorContext
    {
        if ($trigger === self::TRIGGER_RETENTION) {
            return $this->interactiveActor() ?? AuditActorContext::command();
        }

        return $this->executionActor($operation);
    }

    /**
     * The ambient actor ONLY when it is a genuinely identified interactive
     * user. AuditActorResolver::resolve() also returns an unidentified
     * `guest()` context (actor_type `user`, no user id) for a routed request
     * with no authenticated user — no backup path can reach that, and it must
     * never be preferred over an accurate scheduler/command context if one ever
     * did.
     */
    private function interactiveActor(): ?AuditActorContext
    {
        $ambient = $this->actorResolver->resolve();

        return $ambient->actorType === AuditActorType::User && $ambient->actorUserId !== null
            ? $ambient
            : null;
    }

    /**
     * withTrashed(): `created_by` may point at a since-deactivated/soft-deleted
     * Super Admin, and preserving that person's name/email snapshot is more
     * honest than dropping the identity entirely.
     */
    private function initiator(BackupOperation $operation): ?User
    {
        if ($operation->created_by === null) {
            return null;
        }

        $user = User::withTrashed()->find($operation->created_by);

        return $user instanceof User ? $user : null;
    }
}
