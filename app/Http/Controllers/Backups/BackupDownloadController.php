<?php

namespace App\Http\Controllers\Backups;

use App\Http\Controllers\Controller;
use App\Models\BackupOperation;
use App\Services\Backup\BackupFileLock;
use App\Services\Backup\Support\SafeBackupPath;
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
 */
class BackupDownloadController extends Controller
{
    public function show(Request $request, string $backup): StreamedResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless($user->hasRole(PermissionRegistry::SUPER_ADMIN), 403);
        abort_unless($user->can('backups.download'), 403);

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

        $lock = Cache::lock(BackupFileLock::name($operation->uuid), (int) config('oms.backup.lock_ttl', 3600));

        // 423 Locked: the archive exists and is authorized, but is
        // currently being written/deleted (retention) or otherwise held —
        // never silently served and never a generic 404/500.
        abort_unless($lock->get(), 423);

        $filename = $this->safeDownloadFilename($operation);

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ];

        return new StreamedResponse(function () use ($lock, $disk, $storedPath): void {
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
            }
        }, 200, $headers);
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
