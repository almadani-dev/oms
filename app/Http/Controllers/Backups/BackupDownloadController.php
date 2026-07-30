<?php

namespace App\Http\Controllers\Backups;

use App\Http\Controllers\Controller;
use App\Models\BackupOperation;
use App\Services\Audit\BackupRestore\BackupAuditRecorder;
use App\Services\Backup\BackupFileLock;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\Support\SafeBackupPath;
use App\Services\Restore\RestoreActivityGuard;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Secure download foundation for OMS Task 7B.1 — the only route that will
 * ever serve a backup archive's bytes. No Filament UI exists yet (Task
 * 7B.2); this controller/route is the backend it will link to.
 *
 * Never decrypts: the .omsbak.enc file is always served exactly as stored
 * — decryption only ever happens inside a restore process (out of scope
 * here) or BackupIntegrityVerifier's own temporary verification copy.
 *
 * Authorization is deliberately two independent checks, not one: per the
 * OMS Task 7A audit (Section 12), Gate::before grants a real Super Admin
 * every permission automatically, so checking only `backups.download`
 * would not by itself guarantee "Super Admin only" if that permission were
 * ever manually granted to a lesser role — the explicit hasRole() check is
 * what actually enforces that for this Task 7B.1 scope.
 *
 * Holds BackupFileLock::name($operation->uuid) — never the global
 * operation lock — for the *entire* content-transmission lifetime, not
 * merely while this method builds the response object: the release is
 * wired into the StreamedResponse's own callback (invoked by Symfony
 * during sendContent(), i.e. while bytes are actually being written to
 * the client), inside a finally block so it releases whether streaming
 * finishes, fails, or the underlying file vanished out from under it.
 * This is what lets BackupRetentionService safely skip a backup that's
 * mid-download instead of racing it.
 *
 * OMS Task 7C.2: additionally acquires BackupSubsystemLock::acquireShared()
 * before the per-backup BackupFileLock (that exact order — shared
 * subsystem lock, then per-backup lock) and holds it for the identical
 * full transmission lifetime, released in the same StreamedResponse
 * callback finally block. A restore holding the exclusive subsystem lock
 * makes a download attempt return the same 423 a per-backup-lock conflict
 * already returns — from the client's point of view both mean "this
 * archive cannot be read right now."
 *
 * OMS Task 7C.4 correction pass: immediately after acquiring the shared
 * lock, RestoreActivityGuard::blocksOrdinaryOperations() is checked (still
 * holding the shared lock) — closes the parent-launch-to-child-lock-
 * acquisition handoff gap, where the flock() itself has already been
 * released but the detached restore child has not yet acquired its own
 * lifetime exclusive lock. Also returns 423, same as every other lock
 * conflict this controller already reports.
 *
 * OMS Task 9B.6 — auditing. AUTHORIZATION IS STILL CHECKED FIRST, exactly as
 * before; the only change on the denial path is that a refused, already-
 * authenticated caller whose requested UUID resolves to a real backup row now
 * also writes one BEST-EFFORT `backup_download_denied` event before the 403
 * stands (an audit outage can never soften the denial into a 500 — see
 * BackupAuditRecorder::backupDownloadDenied()). Every ordinary 404 case
 * (unknown uuid, non-completed operation, unapproved disk, unsafe stored path,
 * missing file) and every 423 lock conflict deliberately records NOTHING, so a
 * logged-in prober cannot write one audit row per guessed identifier and a
 * "cannot read this right now" answer is not recorded as an access.
 *
 * The success event is REQUIRED and written after every authorization/
 * existence/lock check has passed but BEFORE the StreamedResponse exists: a
 * private encrypted backup that cannot be accounted for is never served. The
 * two locks acquired above are explicitly released if that audit write fails,
 * because the release is otherwise wired into the response callback that
 * would then never be constructed.
 */
class BackupDownloadController extends Controller
{
    public function show(
        Request $request,
        string $backup,
        BackupSubsystemLock $subsystemLock,
        RestoreActivityGuard $restoreActivityGuard,
        BackupAuditRecorder $auditRecorder,
    ): StreamedResponse {
        $user = $request->user();

        abort_if($user === null, 403);

        if (! $user->hasRole(PermissionRegistry::SUPER_ADMIN) || ! $user->can('backups.download')) {
            $this->auditDenial($auditRecorder, $backup);

            abort(403);
        }

        /** @var BackupOperation|null $operation */
        $operation = BackupOperation::query()->where('uuid', $backup)->first();

        abort_if($operation === null, 404);
        abort_unless($operation->isCompleted(), 404);

        $approvedDisk = (string) config('oms.backup.disk', 'backups');
        abort_unless($operation->disk === $approvedDisk, 404);

        $storedPath = (string) $operation->stored_path;
        abort_unless(SafeBackupPath::isSafe($storedPath), 404);

        $disk = Storage::disk($operation->disk);
        abort_unless($disk->exists($storedPath), 404);

        $subsystemHandle = $subsystemLock->acquireShared();

        // 423 Locked: a restore currently holds the exclusive subsystem
        // lock — never silently served and never a generic 404/500.
        abort_if($subsystemHandle === null, 423);

        // 423 Locked: closes the parent-launch-to-child-lock-acquisition
        // handoff gap — a restore has been claimed and has a valid/tampered
        // progress file, but the detached child has not yet (or will
        // never) acquire its own lifetime exclusive lock.
        if ($restoreActivityGuard->blocksOrdinaryOperations()) {
            $subsystemHandle->release();

            abort(423);
        }

        $lock = Cache::lock(BackupFileLock::name($operation->uuid), (int) config('oms.backup.lock_ttl', 3600));

        if (! $lock->get()) {
            // The subsystem lock was already acquired for this request —
            // it must be released before aborting, since abort() throws
            // and skips any code after it in this method.
            $subsystemHandle->release();

            // 423 Locked: the archive exists and is authorized, but is
            // currently being written/deleted (retention) or otherwise
            // held — never silently served and never a generic 404/500.
            abort(423);
        }

        // REQUIRED, before a single byte is served. Both locks are held at this
        // point and their release lives inside the StreamedResponse callback
        // below, which is never invoked if this throws — so they are released
        // here explicitly before the AuditPersistenceException propagates.
        try {
            $auditRecorder->backupDownloaded($operation);
        } catch (\Throwable $e) {
            $lock->release();
            $subsystemHandle->release();

            throw $e;
        }

        $filename = $this->safeDownloadFilename($operation);

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ];

        return new StreamedResponse(function () use ($subsystemHandle, $lock, $disk, $storedPath): void {
            try {
                $stream = $disk->readStream($storedPath);

                if (is_resource($stream)) {
                    $output = fopen('php://output', 'wb');

                    if ($output !== false) {
                        stream_copy_to_stream($stream, $output);
                        fclose($output);
                    }

                    fclose($stream);
                }
            } finally {
                $lock->release();
                $subsystemHandle->release();
            }
        }, 200, $headers);
    }

    /**
     * Records a denial ONLY for a UUID that resolves to a real backup row —
     * the same rule AttachmentAccessAuditRecorder::accessDenied() follows, and
     * the reason a guessed identifier can never create an audit row. The lookup
     * itself is safe: the route already constrains {backup} to the UUID shape
     * before this controller runs, so nothing attacker-shaped reaches the query
     * or the payload.
     */
    private function auditDenial(BackupAuditRecorder $auditRecorder, string $backup): void
    {
        $operation = BackupOperation::query()->where('uuid', $backup)->first();

        if ($operation !== null) {
            $auditRecorder->backupDownloadDenied($operation);
        }
    }

    private function safeDownloadFilename(BackupOperation $operation): string
    {
        $name = $operation->encrypted_filename;

        if (! is_string($name) || $name === '') {
            return "backup-{$operation->id}.omsbak.enc";
        }

        // Defensive: strip any directory segments regardless of separator,
        // and never trust the stored value blindly for a header.
        $name = basename(str_replace('\\', '/', $name));

        if (! str_ends_with($name, '.omsbak.enc')) {
            return "backup-{$operation->id}.omsbak.enc";
        }

        return $name;
    }
}
