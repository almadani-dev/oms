<?php

namespace Tests\Feature\Restore;

use App\Services\Restore\Exceptions\RestoreWorkspaceException;
use App\Services\Restore\RestoreWorkspace;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Backup\FakeSymlinkDetector;

/**
 * OMS Task 7C.3 — RestoreWorkspace: UUID validation, path-escape rejection,
 * restrictive directory creation, and cleanup scoped strictly to a single
 * restore's own "workspace" subtree.
 */
class RestoreWorkspaceTest extends BackupTestCase
{
    private const UUID_A = 'aaaaaaaa-1111-1111-1111-111111111111';

    private const UUID_B = 'bbbbbbbb-2222-2222-2222-222222222222';

    public function test_invalid_uuid_is_rejected(): void
    {
        $this->expectException(RestoreWorkspaceException::class);
        new RestoreWorkspace('not-a-uuid');
    }

    public function test_valid_uuid_is_accepted(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A);
        $this->assertSame(self::UUID_A, $workspace->restoreUuid);
    }

    public function test_prepare_creates_a_private_workspace_directory_without_touching_live_disks(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A);
        $workspace->prepare();

        $this->assertTrue(Storage::disk('restores')->exists(self::UUID_A.'/workspace'));
        $this->assertSame([], Storage::disk('attachments')->allFiles());
    }

    public function test_declared_paths_live_inside_the_workspace_root(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A);
        $workspace->prepare();

        $root = str_replace('\\', '/', $workspace->absoluteRoot());

        foreach ([
            $workspace->decryptedArchivePath(),
            $workspace->stagedDumpPath(),
            $workspace->stagedAttachmentsRoot(),
            $workspace->resolveStagedAttachmentPath('receipts/a.jpg'),
        ] as $path) {
            $this->assertStringStartsWith($root, str_replace('\\', '/', $path));
        }
    }

    public function test_unsafe_relative_attachment_path_is_rejected(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A);
        $workspace->prepare();

        $this->expectException(RestoreWorkspaceException::class);
        $workspace->resolveStagedAttachmentPath('../../etc/passwd');
    }

    public function test_absolute_relative_attachment_path_is_rejected(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A);
        $workspace->prepare();

        $this->expectException(RestoreWorkspaceException::class);
        $workspace->resolveStagedAttachmentPath('/etc/passwd');
    }

    public function test_cleanup_removes_the_workspace_subtree_after_a_simulated_preparation_failure(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A);
        $workspace->prepare();

        file_put_contents($workspace->decryptedArchivePath(), 'plaintext-leftover');
        $this->assertTrue(Storage::disk('restores')->exists(self::UUID_A.'/workspace/archive.zip'));

        $workspace->cleanup();

        $this->assertFalse(Storage::disk('restores')->exists(self::UUID_A.'/workspace'));
    }

    public function test_cleanup_never_removes_sibling_progress_files_or_locks_directory(): void
    {
        $disk = Storage::disk('restores');
        $disk->put(self::UUID_A.'/progress.json', 'signed-progress-content');
        $disk->put(self::UUID_A.'/progress.previous.json', 'signed-previous-content');
        $disk->put('.locks/subsystem.lock', '');

        $workspace = new RestoreWorkspace(self::UUID_A);
        $workspace->prepare();
        file_put_contents($workspace->decryptedArchivePath(), 'plaintext');

        $workspace->cleanup();

        $this->assertFalse($disk->exists(self::UUID_A.'/workspace'));
        $this->assertTrue($disk->exists(self::UUID_A.'/progress.json'));
        $this->assertTrue($disk->exists(self::UUID_A.'/progress.previous.json'));
        $this->assertTrue($disk->exists('.locks/subsystem.lock'));
    }

    public function test_cleanup_never_removes_a_sibling_quarantine_directory(): void
    {
        $disk = Storage::disk('restores');
        $disk->put(self::UUID_A.'/quarantine/receipts/a.jpg', 'quarantined-attachment');

        $workspace = new RestoreWorkspace(self::UUID_A);
        $workspace->prepare();
        file_put_contents($workspace->decryptedArchivePath(), 'plaintext');

        $workspace->cleanup();

        $this->assertFalse($disk->exists(self::UUID_A.'/workspace'));
        $this->assertTrue($disk->exists(self::UUID_A.'/quarantine/receipts/a.jpg'));
    }

    /**
     * Cleaning up restore A's own workspace must never affect restore B's
     * still-active workspace/progress state — proving isolation is what
     * makes "no age-based stale cleanup" safe by construction (see
     * RestoreWorkspace's class docblock).
     */
    public function test_cleaning_up_one_restore_workspace_never_touches_another_active_restores_workspace(): void
    {
        $disk = Storage::disk('restores');
        $disk->put(self::UUID_B.'/progress.json', 'still-active-signed-progress');

        $workspaceB = new RestoreWorkspace(self::UUID_B);
        $workspaceB->prepare();
        file_put_contents($workspaceB->stagedDumpPath(), 'staged-dump-for-active-restore-b');

        $workspaceA = new RestoreWorkspace(self::UUID_A);
        $workspaceA->prepare();
        file_put_contents($workspaceA->decryptedArchivePath(), 'plaintext-for-a');
        $workspaceA->cleanup();

        $this->assertFalse($disk->exists(self::UUID_A.'/workspace'));
        $this->assertTrue($disk->exists(self::UUID_B.'/workspace/database/dump.sql'));
        $this->assertTrue($disk->exists(self::UUID_B.'/progress.json'));
    }

    public function test_cleanup_is_safe_to_call_when_nothing_was_ever_created(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A);

        $workspace->cleanup();

        $this->assertFalse(Storage::disk('restores')->exists(self::UUID_A.'/workspace'));
    }

    // ---- OMS Task 7C.3 correction: symlink/containment hardening ---------------------------

    public function test_resolved_workspace_root_stays_under_the_configured_restores_disk(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A);
        $workspace->prepare();

        $diskRoot = rtrim(str_replace('\\', '/', Storage::disk('restores')->path('')), '/');
        $root = str_replace('\\', '/', $workspace->absoluteRoot());

        $this->assertStringStartsWith($diskRoot, $root);
    }

    /**
     * An existing symlink at the restore's own UUID directory (a level
     * ABOVE "workspace") must block prepare() outright — an
     * attacker-placed symlink there could otherwise silently redirect
     * every path this class resolves outside the restores disk entirely.
     */
    public function test_prepare_refuses_to_proceed_when_the_restore_uuid_directory_itself_is_a_symlink(): void
    {
        $uuidDirAbsolute = rtrim(Storage::disk('restores')->path(self::UUID_A), '/\\');
        $detector = new FakeSymlinkDetector([$uuidDirAbsolute]);

        $workspace = new RestoreWorkspace(self::UUID_A, null, $detector);

        $this->expectException(RestoreWorkspaceException::class);
        $workspace->prepare();
    }

    /**
     * An existing symlink at the "workspace" directory itself must also
     * block prepare().
     */
    public function test_prepare_refuses_to_proceed_when_the_workspace_directory_itself_is_a_symlink(): void
    {
        $workspaceDirAbsolute = rtrim(Storage::disk('restores')->path(self::UUID_A.'/workspace'), '/\\');
        $detector = new FakeSymlinkDetector([$workspaceDirAbsolute]);

        $workspace = new RestoreWorkspace(self::UUID_A, null, $detector);

        $this->expectException(RestoreWorkspaceException::class);
        $workspace->prepare();
    }

    /**
     * Resolving a staged attachment path must refuse to proceed when an
     * existing symlink is found anywhere along the destination's own
     * parent directories inside the workspace.
     */
    public function test_resolve_staged_attachment_path_refuses_when_a_symlink_exists_along_the_way(): void
    {
        $attachmentsDirAbsolute = rtrim(Storage::disk('restores')->path(self::UUID_A.'/workspace/attachments'), '/\\');
        $detector = new FakeSymlinkDetector([$attachmentsDirAbsolute]);

        $workspace = new RestoreWorkspace(self::UUID_A, null, $detector);
        $workspace->prepare();

        $this->expectException(RestoreWorkspaceException::class);
        $workspace->resolveStagedAttachmentPath('receipts/a.jpg');
    }

    /**
     * A clean workspace with no symlinks anywhere along its path must
     * behave exactly as before — the symlink check is not a false-positive
     * trap for the ordinary case.
     */
    public function test_no_symlink_present_does_not_interfere_with_normal_resolution(): void
    {
        $workspace = new RestoreWorkspace(self::UUID_A, null, new FakeSymlinkDetector([]));
        $workspace->prepare();

        $path = $workspace->resolveStagedAttachmentPath('receipts/a.jpg');

        $this->assertStringContainsString('receipts', str_replace('\\', '/', $path));
    }
}
