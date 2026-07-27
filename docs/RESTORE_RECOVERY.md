# Restore Recovery Runbook (OMS Task 7C.7)

Practical operator guide for a restore that is stuck, crashed, or ended in
`RestorePartial`. This document assumes familiarity with the Task 7C
architecture (`BackupSubsystemLock`, the signed `progress.json` protocol,
`RestoreOrchestrator`) — read `RestoreOrchestrator`'s own docblock first if
you have not already.

Never include an encryption key, database password, or confirmation phrase
in any note, ticket, or log excerpt taken from this runbook's guidance.

---

## 1. How to identify an active or stale restore

Two independent signals — check both, they should agree:

- **Database**: a `backup_operations` row with `type = restore` and
  `status = restoring` is an active restore (or one whose process died
  without reaching a terminal state).
- **Signed progress file**: every restore UUID has a directory at
  `storage/app/private/restores/{uuid}/progress.json` (the exact path
  depends on `config('oms.backup.restore.disk')`). A **non-terminal**
  snapshot (`result` is `null`) means the restore has not finished.

The scheduled `oms:restore-watchdog` command (runs every minute) compares
`progress.json`'s `last_heartbeat_at` against
`config('oms.backup.restore.stale_after_minutes')` (default 20) and logs +
sends a persistent notification when a restore looks stale. **It never
mutates, retries, resumes, or acquires any lock** — it is detection only.
Treat its notification as a prompt to start this runbook, not as the
recovery action itself.

## 2. How to locate signed progress safely

```
storage/app/private/restores/{restore-uuid}/progress.json           <- current, authoritative
storage/app/private/restores/{restore-uuid}/progress.previous.json  <- prior snapshot, recovery copy only
```

Never edit `progress.json` by hand — it is cryptographically signed
(`RestoreProgressSigner`) and a tampered file is treated as
`TamperedOrInvalid`, which blocks a new restore for manual review rather
than being silently ignored. Read it (e.g. `cat`/`Get-Content`, or a PHP
`tinker` session using `RestoreProgressReader`) — never write it.

Key fields to read: `phase`, `phase_history`, `result`,
`restore_failed_phase`, `error_summary`, `last_heartbeat_at`,
`reconciliation_snapshot`.

## 3. How to identify the safety-backup UUID

Every restore creates a **mandatory FULL** pre-restore safety backup
(`BackupType::PreRestore`) before any destructive step, regardless of the
restore's own scope. Its UUID is recorded in two places:

- `progress.json`'s top-level `pre_restore_safety_backup_uuid` field.
- `progress.json`'s `reconciliation_snapshot.safety_backup.uuid` (present
  from the `safety_backup_completed` phase onward — this copy survives even
  if the database itself is later replaced/lost).

Confirm it is genuinely usable before relying on it:
`backup_operations` row for that UUID has `status = completed` and a
non-null `verified_at`, and its encrypted archive file exists on the
configured backups disk.

## 4. Before `database_restoring`: safe recovery path

If `phase` in `progress.json` is anywhere at or before `staging` (i.e. the
restore never reached `database_restoring`), **nothing has touched the live
database**, and for `files`/`full` scope attachments may or may not have
been activated — check `phase_history` for `attachments_swapped`.

- If attachments were never activated: nothing destructive happened at all.
  It is safe to leave the system as-is and re-attempt the restore later
  through the normal launch flow (a fresh UUID). No manual cleanup required
  beyond the restore's own workspace (harmless — see §9).
- If attachments WERE activated but the restore died before `database_restoring`:
  check §7 (interrupted attachment activation) below.

## 5. During/failed `database_restoring`: the database may be partial

If `phase` (or `restore_failed_phase`) is `database_restoring`, the `mysql`
import either never started, was interrupted mid-import, or failed
partway. **Do not assume the database is consistent, and do not simply run
`php artisan up`.**

- The database may contain a partially-applied schema/data set.
- Treat the pre-restore safety backup (§3) as the only trustworthy recovery
  source for the CURRENT (pre-this-restore) state, or the ORIGINAL source
  backup for the state this restore was trying to reach — never assume the
  live database itself is safe to keep running against real traffic.
- A Super Admin must explicitly decide whether to restore from the safety
  backup (reverting to pre-restore state) or re-attempt the same source
  backup fresh, before bringing the application back into normal service.
- Maintenance mode should stay enabled until that decision is made and
  acted on manually (`php artisan up` is only safe once you have positively
  confirmed which database state is actually live).

## 6. `database_restored` reached, but reconciliation not complete

If `phase_history` contains `database_restored` but the terminal result is
`restore_partial` with `restore_failed_phase` at or after `reconciling`:

- The `mysql` import itself succeeded.
- Migrations, permission sync, permission cache reset, metadata
  reconstruction, ephemeral cleanup, or `queue:restart` did not all
  complete — check `error_summary` for which step failed
  (`RestoreReconciliationException`'s `reasonCode` values: e.g.
  `migration_failed`, `permission_sync_failed`,
  `metadata_reconstruction_failed`, `ephemeral_cleanup_failed`,
  `queue_restart_failed`).
- **The database is never automatically rolled back.** Attachment
  quarantine (if this was a `files`/`full` restore) is left intact and is
  NOT finalized — do not delete it.
- Recovery is manual: identify and fix the failed step's root cause (e.g. a
  missing migration file, a permissions seeder bug), then re-run the
  specific remaining step by hand under Super Admin supervision — the
  orchestrator does not (yet) support resuming reconciliation alone.

## 7. Interrupted attachment activation

If `progress.json`'s `phase_history` shows `staging` but never reached
`attachments_swapped`, and the restore process is gone, attachment state
may be ambiguous. Inspect it (never guess):

```php
(new \App\Services\Restore\Attachments\AttachmentSwapStateInspector())->inspect($restoreUuid);
```

- `NotActivated` — safe; nothing happened to live attachments.
- `Activated` — live attachments are the restored tree; the original tree
  is intact in quarantine (`attachments.pre_restore.{uuid}`, a sibling of
  the live `attachments` directory).
- `InterruptedDuringActivation` — **live attachments are currently
  missing.** The original tree is safely in quarantine. Do not panic and do
  not manually move directories — this state is what
  `RestoreAttachmentActivationService::rollback()` is specifically designed
  to resume from safely.
- `InconsistentNeedsManualReview` — the signed swap marker and the actual
  directory layout disagree. Do not attempt any move by hand; this requires
  a developer/Super Admin to inspect both the marker file
  (`attachments.pre_restore.{uuid}.state.json`) and the actual directories
  before any action is taken.

## 8. Interrupted rollback

`InterruptedDuringRollback` means: the restored live tree was already moved
aside to a discard location
(`attachments.restore_discard.{uuid}`), but the original quarantine tree
has not yet been moved back into place — live attachments are currently
missing. This is a safely resumable state for
`RestoreAttachmentActivationService::rollback()` — it is specifically
designed to continue from exactly this point. Do not manually rename any
of the three trees (`attachments`, `attachments.pre_restore.{uuid}`,
`attachments.restore_discard.{uuid}`).

## 9. Finalization failure / quarantine left behind

If the terminal result is `restore_partial` and `restore_failed_phase` is
`finalizing`, reconciliation succeeded (for `database`/`full` scope) or
attachments were activated (for `files`/`full`), but the quarantine tree
could not be deleted. **The restored live attachments remain in place and
are correct** — only the disposable quarantine copy is stuck. This is safe
to leave in place while investigating (it consumes disk space but risks
nothing); do not delete it manually without first confirming the live
attachments are genuinely correct and complete.

A disposable **plaintext workspace** cleanup failure (removing the
decrypted staging area under `storage/app/private/restores/{uuid}/workspace`)
is a *separate, lower-severity* case — see `RestoreOrchestrator`'s
Task 7C.7 section H.10 handling: this alone never downgrades an otherwise
successful `Restored` outcome. It is safe to delete that workspace
directory by hand once you have confirmed the restore's own terminal
result.

## 10. Maintenance mode ownership

Check `progress.json`'s `phase_history` for `maintenance_enabled` and
`maintenance_disabled`:

- If `maintenance_disabled` is present, the restore itself both entered and
  successfully left maintenance mode — the application should already be
  reachable.
- If `maintenance_enabled` is present but `maintenance_disabled` is not
  (and the terminal result is not a clean `Restored`), the restore may
  still owns maintenance mode and failed to exit it (`restore_failed_phase
  = maintenance_disabled`), or genuinely never attempted to leave it. Check
  whether the application is currently down (`php artisan --version` still
  works from the CLI even while down; check for the maintenance page over
  HTTP, or inspect whether a maintenance flag is active) before running
  `php artisan up` — confirm first that the underlying restore issue (data
  consistency) has actually been resolved.
- If maintenance mode was **already down before this restore started**
  (checked by the restore itself, never assumed), the restore never calls
  `php artisan up` on its own — this is intentional: it must never bring an
  intentionally-down application back up. Restoring normal service in that
  case is a separate, deliberate operator action, unrelated to this
  restore's own outcome.

## 11. Commands operators may safely run

- Read-only inspection: `php artisan tinker` to read
  `BackupOperation`/read progress files via `RestoreProgressReader`.
- `php artisan oms:restore-watchdog` — safe to run manually any time; it is
  detection-only and idempotent (bounded by its own notification cooldown).
- `php artisan up` — only after you have positively confirmed the
  underlying data/attachment state is actually safe to expose (see §5, §10).
- Restoring the safety backup or the original source backup through the
  normal, confirmed restore launch flow, once a Super Admin has decided
  which state to recover to.

## 12. Commands/actions operators must NOT run blindly

- Do **not** hand-edit `progress.json` or `progress.previous.json`.
- Do **not** manually rename/move `attachments`, `attachments.pre_restore.{uuid}`,
  or `attachments.restore_discard.{uuid}` directories.
- Do **not** delete a quarantine or discard directory until the live
  attachment state has been positively confirmed correct.
- Do **not** run `php artisan up` reflexively just because a restore
  "looks stuck" — confirm data consistency first (§5).
- Do **not** re-run `oms:restore {uuid}` for an already-terminal or
  ambiguous restore UUID — a terminal/tampered progress file is rejected by
  design; a fresh restore requires a new, deliberately confirmed launch.
- Do **not** delete or truncate `backup_operations` rows by hand to "clean
  up" a partial restore — the reconciliation/terminal-write machinery
  expects to find (or safely not find) rows by UUID; manual deletion can
  make a genuinely reconstructible state look unrecoverable.
- Never attempt to resume, retry, or "finish" a restore automatically via a
  script — every recovery action in this document is a deliberate, manual,
  Super-Admin-supervised action.

## 13. Linux production notes

- `rename()` for both the signed progress file and the attachment
  live/quarantine swap is atomic on the same filesystem — a crash mid-swap
  always leaves one of the two well-defined states described in §7/§8,
  never a torn file.
- Maintenance mode is the real Laravel `artisan down`/`up` mechanism (a
  flag file under `storage/framework/`) — confirm its actual presence
  directly on the server if in doubt.
- The restore process is fully detached from the original web request
  (`setsid`) — it keeps running even if the request that launched it ends;
  do not assume a restore stopped just because the launching HTTP request
  returned.

## 14. Windows local-development limitations

- `rename()` on Windows (Laragon) is not POSIX-atomic in the same way as
  Linux, and parent-directory `fsync` is not supported at all — this is a
  documented, accepted local-dev-only limitation (see
  `RestoreProgressWriter`'s own docblock). Never treat Windows-observed
  timing/atomicity behavior as representative of the production (Linux)
  guarantee.
- `is_executable()` does not reliably reflect whether the configured
  `mysql`/`mysqldump` binaries are runnable on Windows — preflight and
  `DatabaseRestorer` both treat an existing regular file as sufficient
  there, unlike Linux where the real executable bit is required.
- These differences affect local reproduction of an issue, never the
  actual recovery guidance above, which applies identically on both
  platforms.
