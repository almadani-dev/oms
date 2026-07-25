<?php

namespace App\Services\Restore;

use App\Services\Backup\Contracts\SymlinkDetector;
use App\Services\Backup\NativeSymlinkDetector;
use App\Services\Backup\Support\SafeBackupPath;
use App\Services\Restore\Exceptions\RestoreWorkspaceException;
use Illuminate\Support\Facades\Storage;

/**
 * OMS Task 7C.3 — the private, transient workspace a single restore's
 * decrypt/verify/extract pipeline stages into:
 *
 *   storage/app/private/restores/{restore_uuid}/workspace/
 *
 * This is a SUBDIRECTORY of the restore's own UUID directory — a sibling of
 * progress.json/progress.previous.json (written by RestoreProgressWriter)
 * and of any future attachment-quarantine directory. cleanup() only ever
 * removes this "workspace" subtree, never the UUID directory itself or any
 * of its other siblings — so progress files, previous-progress snapshots,
 * and quarantine directories are structurally impossible for this class to
 * touch, regardless of when or why cleanup() is called. The `.locks`
 * directory lives at the restores-disk ROOT (a sibling of every UUID
 * directory, not inside one), so it is likewise never reachable from here.
 *
 * The constructor validates $restoreUuid against the same canonical UUID
 * shape RestoreProgressWriter/RestoreActivityGuard already use — an invalid
 * identifier is rejected before any path is ever built from it, so a
 * caller-supplied value can never be used to escape the restores disk root.
 *
 * Workspace creation never touches the live 'attachments' disk or the
 * database — prepare() only ever calls Storage::disk(restores)->makeDirectory().
 *
 * No stale-by-age cleanup exists here (and deliberately so — see the Task
 * 7C.3 scope notes): this class has no time-based sweep of any kind. Safety
 * against ever deleting another restore's in-progress state comes from
 * cleanup() being scoped, by construction, to exactly one restore UUID's
 * own "workspace" subtree and nothing else.
 *
 * Every path this class resolves or creates is checked, via the injectable
 * SymlinkDetector seam, for an existing symlink/junction/reparse point
 * anywhere between the target and the restores-disk root — an
 * attacker-placed symlink could otherwise silently redirect a "safe"
 * relative path outside the workspace entirely, even though the path
 * string itself passed SafeBackupPath's textual validation.
 */
final class RestoreWorkspace
{
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    private const WORKSPACE_SUBDIRECTORY = 'workspace';

    private readonly string $disk;

    private readonly string $relativeRoot;

    private readonly SymlinkDetector $symlinkDetector;

    public function __construct(public readonly string $restoreUuid, ?string $disk = null, ?SymlinkDetector $symlinkDetector = null)
    {
        if (preg_match(self::UUID_PATTERN, $restoreUuid) !== 1) {
            throw RestoreWorkspaceException::invalidRestoreUuid();
        }

        $this->disk = $disk ?? (string) config('oms.backup.restore.disk', 'restores');
        $this->relativeRoot = $restoreUuid.'/'.self::WORKSPACE_SUBDIRECTORY;
        $this->symlinkDetector = $symlinkDetector ?? new NativeSymlinkDetector();
    }

    /**
     * Creates the workspace directory with restrictive permissions where
     * supported (best-effort chmod on Linux; a no-op on Windows). Never
     * touches live attachments or the database. Refuses to proceed if any
     * existing path component between the restores-disk root and the
     * target directory is already a symlink/junction/reparse point — an
     * attacker-placed symlink at `{uuid}` or `{uuid}/workspace` could
     * otherwise silently redirect every subsequent path this class
     * resolves outside the restores disk entirely.
     */
    public function prepare(): void
    {
        $storage = $this->storage();
        $target = $storage->path($this->relativeRoot);

        $this->assertNoSymlinkAlongPath($target);

        if (! $storage->exists($this->relativeRoot)) {
            $storage->makeDirectory($this->relativeRoot);
        }

        @chmod($target, 0700);
    }

    public function absoluteRoot(): string
    {
        return rtrim($this->storage()->path($this->relativeRoot), '/\\');
    }

    public function decryptedArchivePath(): string
    {
        return $this->absolute('archive.zip');
    }

    public function stagedDumpPath(): string
    {
        return $this->absolute('database/dump.sql');
    }

    public function stagedAttachmentsRoot(): string
    {
        return $this->absolute('attachments');
    }

    /**
     * Resolves a caller-supplied, manifest-declared relative attachment
     * path underneath this workspace's own attachments subtree. Every
     * candidate is validated by SafeBackupPath first (rejects '..',
     * absolute/drive-letter paths, backslash traversal, null bytes) and
     * the final absolute path is re-confirmed to still live inside this
     * workspace's own root — no caller-supplied relative path may ever
     * resolve outside it.
     */
    public function resolveStagedAttachmentPath(string $relativePath): string
    {
        if (! SafeBackupPath::isSafe($relativePath)) {
            throw RestoreWorkspaceException::unsafeRelativePath();
        }

        return $this->absolute('attachments/'.$relativePath);
    }

    /**
     * Best-effort removal of this restore's own "workspace" subtree only —
     * never the restore's UUID directory itself, never anything outside
     * it. Safe to call after a failed preparation/extraction attempt; a
     * successful preparation leaves this untouched (cleanup responsibility
     * transfers to the later orchestrator).
     */
    public function cleanup(): void
    {
        $storage = $this->storage();

        if ($storage->exists($this->relativeRoot)) {
            $storage->deleteDirectory($this->relativeRoot);
        }
    }

    private function absolute(string $relative): string
    {
        if (! SafeBackupPath::isSafe($relative)) {
            throw RestoreWorkspaceException::unsafeRelativePath();
        }

        $storage = $this->storage();
        $fullRelative = $this->relativeRoot.'/'.$relative;
        $directory = dirname($fullRelative);
        $directoryAbsolute = $storage->path($directory);

        $this->assertNoSymlinkAlongPath($directoryAbsolute);

        if (! $storage->exists($directory)) {
            $storage->makeDirectory($directory);
        }

        $absolute = $storage->path($fullRelative);
        $this->assertWithinRoot($absolute);

        return $absolute;
    }

    private function assertWithinRoot(string $absolutePath): void
    {
        $root = rtrim(str_replace('\\', '/', $this->absoluteRoot()), '/').'/';
        $candidate = str_replace('\\', '/', $absolutePath);

        if (! str_starts_with($candidate, $root)) {
            throw RestoreWorkspaceException::pathEscapesWorkspace();
        }
    }

    /**
     * Walks from $path up to (and including) the restores-disk ROOT
     * (never further — bounded, never an unbounded walk to the filesystem
     * root), refusing to proceed if any existing component along the way
     * is a symlink/junction/reparse point. Mirrors
     * RestoreArchiveExtractor::assertNoSymlinkAlongDestinationPath()'s own
     * pattern, using the same injectable SymlinkDetector seam (Windows
     * junctions/reparse points are reported the same way NativeSymlinkDetector
     * already handles them for the Backup subsystem — a documented,
     * shared limitation, not a new one introduced here).
     */
    private function assertNoSymlinkAlongPath(string $path): void
    {
        $boundary = rtrim(str_replace('\\', '/', $this->storage()->path('')), '/');
        $current = $path;

        while (true) {
            if ($this->symlinkDetector->isLink($current)) {
                throw RestoreWorkspaceException::pathEscapesWorkspace();
            }

            $normalizedCurrent = rtrim(str_replace('\\', '/', $current), '/');

            if ($normalizedCurrent === $boundary || strlen($normalizedCurrent) <= strlen($boundary)) {
                break;
            }

            $current = dirname($current);
        }
    }

    private function storage()
    {
        return Storage::disk($this->disk);
    }
}
