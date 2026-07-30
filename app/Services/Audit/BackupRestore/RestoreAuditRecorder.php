<?php

namespace App\Services\Audit\BackupRestore;

use App\Enums\AuditActorType;
use App\Enums\AuditFailureMode;
use App\Enums\AuditStatus;
use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Models\BackupOperation;
use App\Models\User;
use App\Services\Audit\AuditActorContext;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use App\Services\Restore\RestoreProgressSnapshot;
use Throwable;

/**
 * The single write path for every RESTORE-side event on
 * `event_category = backup_restore` (OMS Task 9B.6).
 *
 * ===========================================================================
 * THE PROBLEM THIS CLASS EXISTS TO SOLVE: A RESTORE REPLACES `audit_events`.
 * ===========================================================================
 * A database-scope restore imports a full `mysql` dump over the live schema,
 * so the `audit_events` table an event was written to BEFORE the import is
 * physically replaced by the backup's own copy of that table. Any
 * `restore_requested`/`restore_started` row written pre-import is therefore
 * gone by the time the restore succeeds — not because of a bug, but because
 * that is what a restore is.
 *
 * The durable state that survives is the SIGNED RESTORE PROGRESS JOURNAL
 * (RestoreProgressWriter -> `restores/{uuid}/progress.json`), which already
 * existed for exactly this class of problem (OMS Task 7C.2/7C.5) and which this
 * phase deliberately REUSES rather than inventing a second external log:
 *
 *  - it lives on the private `restores` disk, created 0700 with 0600 files;
 *  - it is HMAC-signed (RestoreProgressSigner) and refused by
 *    RestoreProgressReader if tampered with;
 *  - it is bounded and validated field-by-field by RestoreProgressSnapshot,
 *    whose docblock guarantees it can never carry a confirmation phrase, a
 *    password, an encryption key, a raw command line, a stack trace, or an
 *    unbounded exception message — so no new sensitive-data surface is created
 *    by depending on it here;
 *  - since Task 7C.5 it also carries the bounded `reconciliation_snapshot`
 *    (source backup, safety backup, and the restore operation's own requester
 *    identity/confirmation timestamp), written BEFORE the import runs.
 *
 * So the required outcome is met without any new external state, without a
 * schema change, and without a second unencrypted log: after the database has
 * been replaced, the authoritative restore lifecycle is REPLAYED out of that
 * signed journal into the restored `audit_events` table — by
 * RestoreReconciler's final step (replayAfterDatabaseReplacement(), which runs
 * after `migrate --force` has restored the table's schema and after
 * RestoreMetadataUpserter has rebuilt the three `backup_operations` rows), and
 * again as a safety net by RestoreTerminalResultWriter (restoreTerminal(),
 * which covers the case where the import succeeded but reconciliation did not
 * reach the replay step at all).
 *
 * CORRELATION AND IDEMPOTENCY. `correlation_id` is the restore operation's own
 * UUID for every one of these events — the same UUID that names the journal
 * directory and the `backup_operations` row — so the pre-restore and
 * post-restore halves of one restore are linked by a value that is identical on
 * both sides of the database replacement and needs nothing added to the journal
 * to carry it. Every state is written through recordOnce(), which refuses to
 * insert if BackupRestoreAuditLedger already sees that (correlation_id, action)
 * pair. Replay is therefore idempotent by construction: running it twice, or
 * running both the reconciler's replay and the terminal writer's safety-net
 * replay, or re-running a recovery after an interrupted restore, can never
 * produce a duplicate lifecycle row. `oms:sync-permissions` and
 * `permission:cache-reset` (steps 3 and 4 of reconciliation) likewise cannot
 * add restore events — they record nothing at all.
 *
 * ===========================================================================
 * WHICH LIFECYCLE STATES EXIST — AND WHICH DELIBERATELY DO NOT.
 * ===========================================================================
 *  - `restore_requested`  RestoreRequestService::createQueuedRestore(), inside
 *    its existing DB::transaction, REQUIRED. It carries the confirmation actor
 *    and `confirmed_at`, because in this application the confirmation IS the
 *    request: the two-step Filament wizard's typed "RESTORE {uuid8}" phrase and
 *    its final danger-styled step are both validated BEFORE anything is
 *    created, and only that final submit reaches the service. There is no later,
 *    separate confirmation step to audit, so a second "restore_confirmed" event
 *    would describe a UI state that does not exist. The typed phrase itself is
 *    `dehydrated(false)` and never reaches any payload.
 *  - `restore_started`  RestoreLaunchService::claim(), inside the same
 *    transaction as the atomic conditional UPDATE that claims the row,
 *    REQUIRED. Distinct from the request because a queued restore genuinely
 *    can exist without ever being launched.
 *  - `restore_reconciled`  the last step of RestoreReconciler::reconcile(),
 *    only on database/full scopes: the import succeeded, migrations ran,
 *    permissions were synced, and the authoritative metadata rows were rebuilt.
 *  - `restore_completed` / `restore_failed` / `restore_partial`
 *    RestoreTerminalResultWriter::finish(), mapped 1:1 from the real terminal
 *    BackupStatus. `restore_partial` is not an invention: BackupStatus::
 *    RestorePartial is this engine's existing, distinct outcome for "a
 *    destructive boundary was crossed and manual review is required", and
 *    folding it into either success or failure would misreport it.
 *  - `restore_interrupted`  RestoreStaleAcknowledgmentService::acknowledge()
 *    only — the explicit, human-reviewed "this crashed restore is stopped"
 *    action. This is the honest audit of an interrupted restore: the engine
 *    process died without writing any terminal state, and a Super Admin
 *    reviewed and terminalized it.
 *
 * NOT AUDITED, BECAUSE THE PATH DOES NOT EXIST: there is no restore-file
 * upload or file-selection step (a restore always reads an existing, verified
 * BackupOperation archive on the approved disk — nothing is ever uploaded), and
 * there is no cancellation path at all (RestoreStaleAcknowledgmentService is
 * explicitly not cancel/resume/rollback/repair — see its docblock). Neither is
 * invented here. `oms:restore-watchdog` is detection-only and mutates nothing,
 * so it emits nothing.
 *
 * ===========================================================================
 * REQUIRED vs BEST-EFFORT.
 * ===========================================================================
 * The two PRE-restore states are REQUIRED and transactional: nothing may be
 * queued or claimed that could not be recorded. Everything from
 * `restore_reconciled` onwards is BEST-EFFORT and never throws, because every
 * one of those states is written AFTER the database and/or the attachment
 * directories have already been irreversibly changed. Escalating an audit
 * insert failure there could only produce a worse and less truthful outcome:
 * aborting reconciliation would downgrade a genuinely successful restore to
 * RestorePartial, and throwing out of the terminal writer would suppress the
 * terminal record itself. The signed progress journal remains the authoritative
 * terminal record either way (see RestoreTerminalResultWriter's docblock), and
 * a BestEffort failure is still logged with a sanitized fingerprint by
 * AuditLogger.
 *
 * ===========================================================================
 * ACTOR POLICY (§9) AND PAYLOAD POLICY (§5).
 * ===========================================================================
 * A real authenticated interactive request always wins (the Filament request /
 * launch / acknowledgment actions) -> actor_type `user`. Everything the
 * detached `oms:restore` process records -> actor_type `command`, carrying the
 * ORIGINAL requesting user only when that user id still resolves to a real row
 * in whichever database is being written to, and never inventing one when it
 * does not. Either way the requester's bounded identity snapshot (user_id,
 * name, email) from the journal is preserved in the payload, so the person who
 * asked for the restore is recoverable even when their user row did not survive
 * the restore.
 *
 * Payloads carry bounded metadata only: uuids, scope/components, the source
 * archive's already-known size/checksum/manifest version/key identifier,
 * timestamps, phase names from RestoreProgressSnapshot's closed vocabulary,
 * reconciliation status flags, and a generic failure code/category from
 * BackupRestoreFailure. NEVER: archive contents, SQL, a dump, an encryption key
 * or APP_KEY, database credentials, any filesystem path (absolute, relative or
 * temporary), a launch nonce, a confirmation phrase, process output, a stack
 * trace, or an exception message.
 */
final class RestoreAuditRecorder
{
    public const EVENT_CATEGORY = BackupRestoreAuditSubject::EVENT_CATEGORY;

    public const ACTION_REQUESTED = 'restore_requested';

    public const ACTION_STARTED = 'restore_started';

    public const ACTION_RECONCILED = 'restore_reconciled';

    public const ACTION_COMPLETED = 'restore_completed';

    public const ACTION_FAILED = 'restore_failed';

    public const ACTION_PARTIAL = 'restore_partial';

    public const ACTION_INTERRUPTED = 'restore_interrupted';

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AuditActorResolver $actorResolver,
        private readonly BackupRestoreAuditLedger $ledger,
    ) {}

    /**
     * REQUIRED, from inside RestoreRequestService's own transaction.
     *
     * @throws \App\Services\Audit\Exceptions\AuditPersistenceException
     */
    public function restoreRequested(BackupOperation $restore, ?BackupOperation $sourceBackup): void
    {
        $facts = RestoreAuditFacts::fromRows($restore, $sourceBackup);

        if ($this->ledger->recorded($facts->restoreUuid, self::ACTION_REQUESTED)) {
            return;
        }

        $this->write(
            $facts,
            self::ACTION_REQUESTED,
            AuditStatus::Success,
            ['status' => $restore->status->value],
            AuditFailureMode::Required,
        );
    }

    /**
     * REQUIRED, from inside RestoreLaunchService's atomic claim transaction, so
     * a restore process is never spawned for a claim that could not be
     * recorded — the row stays Queued with its nonce and can be relaunched.
     *
     * @throws \App\Services\Audit\Exceptions\AuditPersistenceException
     */
    public function restoreStarted(BackupOperation $claimed): void
    {
        $facts = RestoreAuditFacts::fromRows($claimed, $claimed->sourceBackup);

        if ($this->ledger->recorded($facts->restoreUuid, self::ACTION_STARTED)) {
            return;
        }

        $this->write(
            $facts,
            self::ACTION_STARTED,
            AuditStatus::Success,
            ['status' => $claimed->status->value],
            AuditFailureMode::Required,
        );
    }

    /**
     * BEST-EFFORT. A launch that was claimed but could neither initialize its
     * progress journal nor spawn its process — the row is already terminal
     * (RestoreFailed) by the time this is called, so nothing here may throw.
     */
    public function restoreLaunchFailed(BackupOperation $claimed, string $reasonCode): void
    {
        $this->safely(function () use ($claimed, $reasonCode): void {
            $facts = RestoreAuditFacts::fromRows($claimed, $claimed->sourceBackup);

            if ($this->ledger->recorded($facts->restoreUuid, self::ACTION_FAILED)) {
                return;
            }

            $this->write(
                $facts,
                self::ACTION_FAILED,
                AuditStatus::Failure,
                [
                    'status' => BackupStatus::RestoreFailed->value,
                    'failed_phase' => 'launching',
                    'failed_at' => $claimed->failed_at?->toIso8601String(),
                ] + BackupRestoreFailure::payload(null, $reasonCode),
                AuditFailureMode::BestEffort,
            );
        });
    }

    /**
     * BEST-EFFORT, idempotent, and never throws — the authoritative post-
     * database-replacement replay. Called as RestoreReconciler's final step,
     * i.e. only after the import, `migrate --force`, permission sync, metadata
     * reconstruction and ephemeral cleanup have all succeeded, so the table
     * being written to is the restored one and the `backup_operations` rows
     * these events reference already exist again.
     *
     * Writes the two pre-restore states first (they were lost with the replaced
     * database) and then `restore_reconciled`, each at most once.
     */
    public function replayAfterDatabaseReplacement(
        BackupOperationSnapshot $source,
        BackupOperationSnapshot $safety,
        RestoreOperationSnapshot $restore,
    ): void {
        $this->safely(function () use ($source, $safety, $restore): void {
            $facts = RestoreAuditFacts::fromReconciliation($restore, $source, $safety);

            $this->replayPreRestoreStates($facts);

            $this->recordOnce(
                $facts,
                self::ACTION_RECONCILED,
                AuditStatus::Success,
                [
                    'status' => BackupStatus::Restoring->value,
                    'phase' => 'reconciling',
                    'reconciliation' => [
                        'database_imported' => true,
                        'migrations_applied' => true,
                        'permissions_synced' => true,
                        'metadata_rows_reconstructed' => true,
                    ],
                    'recovery_state' => 'database_replaced_and_reconciled',
                    'replayed_after_database_replacement' => true,
                ],
            );
        });
    }

    /**
     * BEST-EFFORT, idempotent, never throws — the single terminal event for
     * every restore outcome, written from RestoreTerminalResultWriter (the one
     * funnel every RestoreOrchestrator failure/success branch already goes
     * through, so no branch can add a second terminal event).
     *
     * Re-runs the pre-restore replay first: when the import succeeded but
     * reconciliation never reached its replay step, this is the only remaining
     * chance to put the request/claim history into the restored table. When the
     * database was never replaced (any pre-import failure) those two events are
     * already present and the ledger makes this a no-op.
     */
    public function restoreTerminal(RestoreProgressSnapshot $terminal, BackupStatus $result, ?Throwable $cause = null): void
    {
        $this->safely(function () use ($terminal, $result, $cause): void {
            $action = match ($result) {
                BackupStatus::Restored => self::ACTION_COMPLETED,
                BackupStatus::RestoreFailed => self::ACTION_FAILED,
                BackupStatus::RestorePartial => self::ACTION_PARTIAL,
                default => null,
            };

            if ($action === null) {
                return;
            }

            $facts = RestoreAuditFacts::fromProgress($terminal);
            $succeeded = $result === BackupStatus::Restored;

            $this->replayPreRestoreStates($facts);

            $extra = [
                'status' => $result->value,
                'phase' => $terminal->phase,
                'completed_at' => $succeeded ? $terminal->lastHeartbeatAt : null,
                'failed_at' => $succeeded ? null : $terminal->lastHeartbeatAt,
                'failed_phase' => $terminal->restoreFailedPhase,
                // A restore that reached a terminal state without the journal
                // ever carrying a reconciliation snapshot never got as far as
                // the destructive boundary, so it is honest to report that the
                // database was not replaced at all.
                'recovery_state' => $this->recoveryState($terminal, $result),
            ];

            if (! $succeeded) {
                $extra += BackupRestoreFailure::payload($cause);
            }

            $this->recordOnce($facts, $action, $succeeded ? AuditStatus::Success : AuditStatus::Failure, $extra);
        });
    }

    /**
     * BEST-EFFORT, idempotent, never throws. The explicit human acknowledgment
     * that a crashed restore is stopped — the actor is the reviewing Super
     * Admin, and the acknowledgment reason is carried in the event's `reason`
     * column (bounded and sanitized by the caller), never a path or raw
     * exception text.
     */
    public function restoreInterrupted(RestoreProgressSnapshot $progress, User $actor, string $reason): void
    {
        $this->safely(function () use ($progress, $actor, $reason): void {
            $facts = RestoreAuditFacts::fromProgress($progress);

            if ($this->ledger->recorded($facts->restoreUuid, self::ACTION_INTERRUPTED)) {
                return;
            }

            $this->write(
                $facts,
                self::ACTION_INTERRUPTED,
                AuditStatus::Failure,
                [
                    'status' => BackupStatus::RestoreFailed->value,
                    'phase' => $progress->phase,
                    'failed_phase' => 'crashed_acknowledged',
                    'recovery_state' => 'interrupted_acknowledged_by_super_admin',
                    'acknowledged_by' => [
                        'user_id' => $actor->getKey(),
                        'name' => (string) $actor->name,
                        'email' => (string) $actor->email,
                    ],
                    'acknowledged_at' => now()->toIso8601String(),
                ],
                AuditFailureMode::BestEffort,
                actor: AuditActorContext::forUser($actor),
                reason: $reason,
            );
        });
    }

    /**
     * The two states that a replaced database always loses. Written from the
     * durable journal facts, marked as replayed so a reader can tell a
     * reconstructed row from an original one, and each guarded by the ledger so
     * this can be called from more than one recovery point safely.
     */
    private function replayPreRestoreStates(RestoreAuditFacts $facts): void
    {
        $this->recordOnce($facts, self::ACTION_REQUESTED, AuditStatus::Success, [
            'status' => BackupStatus::Queued->value,
            'replayed_after_database_replacement' => true,
        ]);

        $this->recordOnce($facts, self::ACTION_STARTED, AuditStatus::Success, [
            'status' => BackupStatus::Restoring->value,
            'replayed_after_database_replacement' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordOnce(RestoreAuditFacts $facts, string $action, AuditStatus $status, array $extra): void
    {
        if ($this->ledger->recorded($facts->restoreUuid, $action)) {
            return;
        }

        $this->write($facts, $action, $status, $extra, AuditFailureMode::BestEffort);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function write(
        RestoreAuditFacts $facts,
        string $action,
        AuditStatus $status,
        array $extra,
        AuditFailureMode $mode,
        ?AuditActorContext $actor = null,
        ?string $reason = null,
    ): void {
        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: $action,
                actor: $actor ?? $this->actor($facts),
                status: $status,
                subjectType: BackupRestoreAuditSubject::Restore->value,
                subjectKey: $facts->restoreUuid,
                subjectLabel: $this->label($facts),
                newValues: $this->payload($facts, $extra),
                reason: $reason ?? $facts->reason,
                correlationId: $facts->restoreUuid,
            ),
            $mode,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(RestoreAuditFacts $facts, array $extra): array
    {
        $scope = BackupScope::tryFrom($facts->scope);

        return [
            'restore_uuid' => $facts->restoreUuid,
            'source_backup_uuid' => $facts->sourceBackupUuid,
            'pre_restore_safety_backup_uuid' => $facts->safetyBackupUuid,
            'scope' => $facts->scope,
            'components' => [
                'database' => $scope?->includesDatabase() ?? false,
                'private_attachments' => $scope?->includesFiles() ?? false,
            ],
            'requested_by' => $facts->requestedBy,
            'requested_at' => $facts->requestedAt,
            'confirmed_at' => $facts->confirmedAt,
            'started_at' => $facts->startedAt,
            'source_archive_size_bytes' => $facts->sourceArchiveSizeBytes,
            'source_archive_checksum_sha256' => $facts->sourceArchiveChecksumSha256,
            'source_manifest_version' => $facts->sourceManifestVersion,
            // Must keep this exact field name: AuditRedactor allowlists
            // `encryption_key_id` specifically (any other name containing a
            // `key` segment would be redacted), and it identifies WHICH key the
            // source archive was encrypted with — never key material.
            'encryption_key_id' => $facts->sourceEncryptionKeyId,
        ] + $extra;
    }

    private function recoveryState(RestoreProgressSnapshot $terminal, BackupStatus $result): string
    {
        if ($result === BackupStatus::RestorePartial) {
            return 'partial_manual_review_required';
        }

        if ($terminal->reconciliationSnapshot === null) {
            return 'no_destructive_change_recorded';
        }

        return $result === BackupStatus::Restored ? 'restore_finalized' : 'aborted_after_safety_backup';
    }

    private function label(RestoreAuditFacts $facts): string
    {
        return sprintf('restore/%s %s', $facts->scope, substr($facts->restoreUuid, 0, 8));
    }

    /**
     * Interactive user when a genuine authenticated routed request exists,
     * otherwise the detached restore process's own `command` context — carrying
     * the original requester only when that user id still resolves in the
     * database currently being written to (it may not, after a restore).
     */
    private function actor(RestoreAuditFacts $facts): AuditActorContext
    {
        $ambient = $this->actorResolver->resolve();

        if ($ambient->actorType === AuditActorType::User && $ambient->actorUserId !== null) {
            return $ambient;
        }

        return AuditActorContext::command($this->requester($facts));
    }

    private function requester(RestoreAuditFacts $facts): ?User
    {
        $userId = $facts->requestedBy['user_id'] ?? null;

        if (! is_int($userId)) {
            return null;
        }

        $user = User::withTrashed()->find($userId);

        return $user instanceof User ? $user : null;
    }

    /**
     * Every post-irreversible-boundary event goes through here: BestEffort
     * already covers an audit INSERT failure, but the ledger probe, the
     * requester lookup and the payload build all touch the same database that
     * would be failing in that scenario, so the whole unit is contained.
     */
    private function safely(callable $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            // Never allowed to affect the restore outcome it describes.
        }
    }
}
