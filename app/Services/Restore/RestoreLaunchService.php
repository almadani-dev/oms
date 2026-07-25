<?php

namespace App\Services\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Restore\Contracts\RestoreProcessLauncher;
use App\Services\Restore\Exceptions\RestoreProcessLaunchException;
use App\Support\Backup\BackupErrorSanitizer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OMS Task 7C.4 — the only place a queued restore is ever claimed and
 * spawned. Launches an already-created, fully validated queued restore
 * operation; it never creates one (the request/confirmation UI is a later
 * phase — tests construct a valid queued row directly).
 *
 * The whole critical section (dual activity check excluding this restore's
 * own UUID, the atomic DB claim, the initial signed progress write, and the
 * detached process spawn) runs under one short-lived exclusive
 * BackupSubsystemLock, held through every success or failure path and
 * released in every one of them — no peek-and-release, no path that leaves
 * the lock held or the row/progress file in a half-updated state.
 */
final class RestoreLaunchService
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    /**
     * Bounds restore_metadata['launch_audit'] the same way
     * RestoreProgressSnapshot bounds phase_history — never keys derived
     * from user input, never a nonce, never a confirmation phrase.
     */
    private const MAX_LAUNCH_AUDIT_ENTRIES = 20;

    /**
     * Defense in depth: a queued restore row must never carry any of these
     * keys in restore_metadata — claiming fails closed if one is present,
     * even though nothing in this codebase currently writes them.
     */
    private const FORBIDDEN_METADATA_KEYS = [
        'confirmation_phrase', 'password', 'encryption_key', 'key',
        'db_password', 'database_password', 'secret',
    ];

    public function __construct(
        private readonly RestoreProcessLauncher $processLauncher,
        private readonly BackupSubsystemLock $subsystemLock = new BackupSubsystemLock(),
        private readonly RestoreActivityGuard $activityGuard = new RestoreActivityGuard(),
        private readonly RestoreProgressWriter $progressWriter = new RestoreProgressWriter(),
    ) {
    }

    public function launch(string $restoreUuid, string $nonce): RestoreLaunchOutcome
    {
        if (preg_match(self::UUID_PATTERN, $restoreUuid) !== 1) {
            return RestoreLaunchOutcome::conflict('invalid_uuid');
        }

        if ($nonce === '') {
            return RestoreLaunchOutcome::conflict('missing_nonce');
        }

        $row = BackupOperation::query()->where('uuid', $restoreUuid)->first();

        if ($row === null) {
            return RestoreLaunchOutcome::conflict('not_found');
        }

        $validationFailure = $this->validationFailureReason($row, $nonce);

        if ($validationFailure !== null) {
            return RestoreLaunchOutcome::conflict($validationFailure);
        }

        $lockHandle = $this->subsystemLock->acquireExclusive();

        if ($lockHandle === null) {
            return RestoreLaunchOutcome::locked();
        }

        try {
            $activity = $this->activityGuard->isActive($restoreUuid);

            if ($activity !== RestoreActivityState::Inactive) {
                return RestoreLaunchOutcome::conflict(
                    $activity === RestoreActivityState::TamperedOrInvalid
                        ? 'restore_state_requires_review'
                        : 'restore_already_active',
                );
            }

            $claimed = $this->claim($row, $nonce);

            if ($claimed === null) {
                return RestoreLaunchOutcome::conflict('claim_lost_race');
            }

            try {
                $snapshot = $this->buildInitialSnapshot($claimed);
                $this->progressWriter->write($snapshot);
            } catch (Throwable) {
                $this->markFailed($claimed, 'progress_write_failed');

                return RestoreLaunchOutcome::failed('progress_write_failed');
            }

            try {
                $this->processLauncher->launch($restoreUuid);
            } catch (RestoreProcessLaunchException $e) {
                $this->markFailed($claimed, $e->reasonCode);

                return RestoreLaunchOutcome::failed($e->reasonCode);
            }

            return RestoreLaunchOutcome::accepted($restoreUuid, $claimed->status->value);
        } finally {
            $lockHandle->release();
        }
    }

    /**
     * Every OMS Task 7C.4 precondition a queued restore row must satisfy
     * before it may even be considered for claiming — independent of, and
     * checked before, the atomic conditional UPDATE itself.
     */
    private function validationFailureReason(BackupOperation $row, string $nonce): ?string
    {
        if ($row->type !== BackupType::Restore) {
            return 'not_a_restore_operation';
        }

        if ($row->status !== BackupStatus::Queued) {
            return 'not_queued';
        }

        if ($row->started_at !== null) {
            return 'already_started';
        }

        $storedNonce = $row->launch_nonce;

        if (! is_string($storedNonce) || $storedNonce === '' || ! hash_equals($storedNonce, $nonce)) {
            return 'nonce_mismatch';
        }

        if ($row->source_backup_id === null) {
            return 'source_backup_missing';
        }

        $sourceBackup = $row->sourceBackup;

        if ($sourceBackup === null || ! $sourceBackup->isCompleted() || $sourceBackup->verified_at === null) {
            return 'source_backup_invalid';
        }

        if (! is_string($row->operation_reason) || trim($row->operation_reason) === '') {
            return 'reason_missing';
        }

        if (! ($row->scope instanceof BackupScope)) {
            return 'scope_invalid';
        }

        $metadata = $row->restore_metadata;

        if (! is_array($metadata)) {
            return 'metadata_missing';
        }

        foreach (self::FORBIDDEN_METADATA_KEYS as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $metadata)) {
                return 'metadata_forbidden_key';
            }
        }

        $requester = $metadata['requester'] ?? null;

        if (
            ! is_array($requester)
            || ! array_key_exists('user_id', $requester)
            || ! array_key_exists('name', $requester)
            || ! array_key_exists('email', $requester)
            || ($requester['user_id'] !== null && ! is_int($requester['user_id']))
            || ! is_string($requester['name']) || $requester['name'] === ''
            || ! is_string($requester['email']) || $requester['email'] === ''
        ) {
            return 'requester_snapshot_invalid';
        }

        $confirmedAt = $metadata['confirmed_at'] ?? null;

        if (
            ! is_string($confirmedAt) || $confirmedAt === ''
            || DateTimeImmutable::createFromFormat(RestoreProgressSnapshot::TIMESTAMP_FORMAT, $confirmedAt) === false
        ) {
            return 'confirmation_missing';
        }

        return null;
    }

    /**
     * The single atomic conditional UPDATE: WHERE id AND type=restore AND
     * status=queued AND started_at IS NULL AND launch_nonce=$nonce. Exactly
     * one row affected means this request won the claim; zero means a
     * replay or a lost race against a concurrent request for the SAME row
     * — never spawns twice, never partially updates. Deliberately a query-
     * builder update (not Eloquent attribute assignment) so the WHERE
     * clause is evaluated atomically by the database itself, not read-then-
     * written from PHP.
     */
    private function claim(BackupOperation $row, string $nonce): ?BackupOperation
    {
        return DB::transaction(function () use ($row, $nonce): ?BackupOperation {
            $now = now();

            $metadata = is_array($row->restore_metadata) ? $row->restore_metadata : [];
            $launchAudit = is_array($metadata['launch_audit'] ?? null) ? $metadata['launch_audit'] : [];
            $launchAudit[] = ['launched_at' => $now->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT)];
            $metadata['launch_audit'] = array_slice($launchAudit, -self::MAX_LAUNCH_AUDIT_ENTRIES);

            $affected = BackupOperation::query()
                ->where('id', $row->id)
                ->where('type', BackupType::Restore->value)
                ->where('status', BackupStatus::Queued->value)
                ->whereNull('started_at')
                ->where('launch_nonce', $nonce)
                ->update([
                    'started_at' => $now->format('Y-m-d H:i:s'),
                    'status' => BackupStatus::Restoring->value,
                    'launch_nonce' => null,
                    'restore_metadata' => json_encode($metadata),
                ]);

            if ($affected !== 1) {
                return null;
            }

            return BackupOperation::query()->findOrFail($row->id);
        });
    }

    private function buildInitialSnapshot(BackupOperation $claimed): RestoreProgressSnapshot
    {
        $metadata = is_array($claimed->restore_metadata) ? $claimed->restore_metadata : [];
        $now = now()->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

        return RestoreProgressSnapshot::create(
            restoreUuid: $claimed->uuid,
            requestedBy: $this->requesterFromMetadata($metadata),
            requestedAt: $claimed->created_at?->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT) ?? $now,
            reason: (string) $claimed->operation_reason,
            scope: $claimed->scope->value,
            sourceBackupUuid: (string) $claimed->sourceBackup?->uuid,
            preRestoreSafetyBackupUuid: $claimed->preRestoreSafetyBackup?->uuid,
            phase: 'launching',
            phaseHistory: [['phase' => 'launching', 'at' => $now]],
            lastHeartbeatAt: $now,
            result: null,
            restoreFailedPhase: null,
            errorSummary: null,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{user_id: int|null, name: string, email: string}
     */
    private function requesterFromMetadata(array $metadata): array
    {
        $requester = is_array($metadata['requester'] ?? null) ? $metadata['requester'] : [];

        return [
            'user_id' => is_int($requester['user_id'] ?? null) ? $requester['user_id'] : null,
            'name' => (string) ($requester['name'] ?? ''),
            'email' => (string) ($requester['email'] ?? ''),
        ];
    }

    /**
     * Terminal failure path for both the progress-write failure and the
     * launcher failure: the DB row is the authoritative terminal record
     * (always updated), the progress file update is best-effort (a failure
     * here must never mask or throw past the DB update already made).
     * launch_nonce is never restored — it was already consumed by claim(),
     * so a replayed URL can never retry this same row.
     */
    private function markFailed(BackupOperation $claimed, string $reasonCode): void
    {
        $now = now();
        $sanitizedSummary = BackupErrorSanitizer::sanitize("Restore launch failed: {$reasonCode}");

        $metadata = is_array($claimed->restore_metadata) ? $claimed->restore_metadata : [];
        $launchAudit = is_array($metadata['launch_audit'] ?? null) ? $metadata['launch_audit'] : [];
        $launchAudit[] = ['failed_at' => $now->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT), 'reason' => $reasonCode];
        $metadata['launch_audit'] = array_slice($launchAudit, -self::MAX_LAUNCH_AUDIT_ENTRIES);

        $claimed->forceFill([
            'status' => BackupStatus::RestoreFailed,
            'failed_at' => $now,
            'error_summary' => $sanitizedSummary,
            'restore_metadata' => $metadata,
        ])->save();

        try {
            $nowAtom = $now->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT);

            $terminalSnapshot = RestoreProgressSnapshot::create(
                restoreUuid: $claimed->uuid,
                requestedBy: $this->requesterFromMetadata($metadata),
                requestedAt: $claimed->created_at?->format(RestoreProgressSnapshot::TIMESTAMP_FORMAT) ?? $nowAtom,
                reason: (string) $claimed->operation_reason,
                scope: $claimed->scope->value,
                sourceBackupUuid: (string) $claimed->sourceBackup?->uuid,
                preRestoreSafetyBackupUuid: $claimed->preRestoreSafetyBackup?->uuid,
                phase: 'restore_failed',
                phaseHistory: [['phase' => 'launching', 'at' => $nowAtom]],
                lastHeartbeatAt: $nowAtom,
                result: 'restore_failed',
                restoreFailedPhase: 'launching',
                errorSummary: $sanitizedSummary,
            );

            $this->progressWriter->write($terminalSnapshot);
        } catch (Throwable) {
            // Best-effort only — the DB row above is already the
            // authoritative terminal record; a progress-file write failure
            // here must never throw back out of a failure-handling path.
        }
    }
}
