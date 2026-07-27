<?php

namespace Tests\Feature\Restore\Attachments;

use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\BackupSubsystemLockHandle;
use App\Services\Restore\Attachments\AttachmentSwapHandle;
use App\Services\Restore\Attachments\AttachmentSwapMarkerReader;
use App\Services\Restore\Attachments\AttachmentSwapMarkerWriter;
use App\Services\Restore\Attachments\AttachmentSwapState;
use App\Services\Restore\Attachments\AttachmentSwapStateInspector;
use App\Services\Restore\Attachments\NativeAttachmentMoveRunner;
use App\Services\Restore\Attachments\RestoreAttachmentActivationService;
use App\Services\Restore\Attachments\RestoreAttachmentManifest;
use App\Services\Restore\Attachments\RestoreAttachmentPaths;
use App\Services\Restore\Attachments\RestoreAttachmentRevalidator;
use App\Services\Restore\Exceptions\RestoreAttachmentFinalizationException;
use App\Services\Restore\Exceptions\RestoreAttachmentRollbackException;
use App\Services\Restore\Exceptions\RestoreAttachmentSwapException;
use App\Services\Restore\Exceptions\RestoreAttachmentValidationException;
use App\Services\Restore\RestoreWorkspace;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Restore\FakeAttachmentMoveRunner;
use Tests\Support\Restore\FakeRestoreProgressDurability;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — RestoreAttachmentActivationService:
 * activate()/rollback()/finalize(), lock enforcement, the Linux/Windows
 * rename swap sequence, and the write-ahead signed-marker discipline (a
 * marker-durability failure immediately after a successful mutation must
 * fail closed to manual review rather than silently continuing).
 */
class RestoreAttachmentActivationServiceTest extends BackupTestCase
{
    // OMS Task 7C.7 hardening pass — generated fresh per test (was
    // previously a fixed class constant shared by every test method here)
    // so this file can never collide with another test file's own
    // quarantine/discard/marker sibling-path state for the "same" UUID,
    // regardless of execution order. The explicit cleanupSwapArtifacts()
    // calls below are kept anyway as defense in depth (e.g. a crashed prior
    // run that never reached tearDown()), but a fresh UUID means this
    // file's own tests can never depend on — or be defeated by — another
    // test class's cleanup discipline.
    private string $uuid;

    private RestoreAttachmentPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uuid = (string) \Illuminate\Support\Str::uuid();
        $this->paths = new RestoreAttachmentPaths();
        $this->cleanupSwapArtifacts();
    }

    protected function tearDown(): void
    {
        $this->cleanupSwapArtifacts();

        parent::tearDown();
    }

    /**
     * Storage::fake('attachments')/('restores') only reset each disk's own
     * root — the sibling quarantine/discard/marker paths this class
     * computes live one level up and are NOT reset by it, so leftover state
     * from an earlier test in the same process would otherwise leak into
     * the next one using the same UUID.
     */
    private function cleanupSwapArtifacts(): void
    {
        $this->removeDirectory($this->paths->quarantineRoot($this->uuid));
        $this->removeDirectory($this->paths->rollbackDiscardRoot($this->uuid));

        $marker = $this->paths->markerPath($this->uuid);

        if (is_file($marker)) {
            unlink($marker);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $dir.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($dir);
    }

    private function service(?FakeAttachmentMoveRunner $mover = null, ?AttachmentSwapMarkerWriter $markerWriter = null): RestoreAttachmentActivationService
    {
        return new RestoreAttachmentActivationService(
            new RestoreAttachmentRevalidator(),
            $mover ?? new NativeAttachmentMoveRunner(maxAttempts: 1, retryDelayMs: 0),
            markerWriter: $markerWriter ?? new AttachmentSwapMarkerWriter(),
        );
    }

    private function exclusiveHandle(): BackupSubsystemLockHandle
    {
        $handle = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($handle, 'Test setup expected to acquire the exclusive subsystem lock.');

        return $handle;
    }

    private function preparedWorkspace(): RestoreWorkspace
    {
        $workspace = new RestoreWorkspace($this->uuid);
        $workspace->prepare();

        return $workspace;
    }

    private function stageAttachment(RestoreWorkspace $workspace, string $relativePath, string $content): array
    {
        file_put_contents($workspace->resolveStagedAttachmentPath($relativePath), $content);

        return ['path' => $relativePath, 'sha256' => hash('sha256', $content), 'size' => strlen($content)];
    }

    private function manifestFor(array $entries, int $totalBytes): RestoreAttachmentManifest
    {
        return RestoreAttachmentManifest::fromManifestFiles($entries, $totalBytes);
    }

    private function inspect(): AttachmentSwapState
    {
        return (new AttachmentSwapStateInspector())->inspect($this->uuid);
    }

    private function failingMarkerWriter(): AttachmentSwapMarkerWriter
    {
        return new AttachmentSwapMarkerWriter(new FakeRestoreProgressDurability(syncFileResult: false));
    }

    /** A marker writer whose Nth write() call fails (1-based), all others succeed. */
    private function markerWriterFailingOnCall(int $failingCall): AttachmentSwapMarkerWriter
    {
        $durability = new FakeRestoreProgressDurability();
        $durability->onSyncFile = static fn (int $callIndex): bool => $callIndex !== $failingCall;

        return new AttachmentSwapMarkerWriter($durability);
    }

    // ---- activate() ------------------------------------------------------

    public function test_activate_moves_live_to_quarantine_and_staged_becomes_live(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            $this->assertInstanceOf(AttachmentSwapHandle::class, $swap);
            $this->assertTrue(Storage::disk('attachments')->exists('receipts/new.jpg'));
            $this->assertFalse(Storage::disk('attachments')->exists('receipts/original.jpg'));
            $this->assertSame('new-content', Storage::disk('attachments')->get('receipts/new.jpg'));
            $this->assertSame(AttachmentSwapState::Activated, $this->inspect());
        } finally {
            $handle->release();
        }
    }

    public function test_quarantine_is_retained_and_contains_the_original_tree_after_successful_activation(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            $quarantine = $this->paths->quarantineRoot($this->uuid);
            $this->assertTrue(is_dir($quarantine));
            $this->assertSame('original-content', file_get_contents($quarantine.DIRECTORY_SEPARATOR.'receipts'.DIRECTORY_SEPARATOR.'original.jpg'));
        } finally {
            $handle->release();
        }
    }

    public function test_activate_succeeds_with_an_empty_live_tree(): void
    {
        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            $this->assertTrue(Storage::disk('attachments')->exists('receipts/new.jpg'));
        } finally {
            $handle->release();
        }
    }

    public function test_activate_creates_a_missing_but_safely_creatable_live_root(): void
    {
        rmdir($this->paths->liveRoot());
        $this->assertFalse(is_dir($this->paths->liveRoot()));

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            $this->assertTrue(Storage::disk('attachments')->exists('receipts/new.jpg'));
        } finally {
            $handle->release();
        }
    }

    public function test_first_rename_failure_leaves_live_attachments_unchanged(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $mover = new FakeAttachmentMoveRunner([true]);
        $handle = $this->exclusiveHandle();

        try {
            $this->expectException(RestoreAttachmentSwapException::class);
            $this->service($mover)->activate($handle, $this->uuid, $workspace, $manifest);
        } finally {
            $this->assertTrue(Storage::disk('attachments')->exists('receipts/original.jpg'));
            $this->assertFalse(is_dir($this->paths->quarantineRoot($this->uuid)));
            $handle->release();
        }
    }

    public function test_second_rename_failure_rolls_the_original_attachments_back(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        // First move (live -> quarantine) succeeds; second move (staged -> live) fails.
        $mover = new FakeAttachmentMoveRunner([false, true]);
        $handle = $this->exclusiveHandle();

        try {
            $this->expectException(RestoreAttachmentSwapException::class);
            $this->service($mover)->activate($handle, $this->uuid, $workspace, $manifest);
        } finally {
            $this->assertTrue(Storage::disk('attachments')->exists('receipts/original.jpg'), 'Live attachments must never be left missing after a failed activation.');
            $this->assertFalse(is_dir($this->paths->quarantineRoot($this->uuid)), 'Quarantine must be reabsorbed back into live after an emergency rollback.');
            $this->assertSame(AttachmentSwapState::RolledBack, $this->inspect());
            $handle->release();
        }
    }

    public function test_unexpected_existing_quarantine_refuses_to_activate(): void
    {
        mkdir($this->paths->quarantineRoot($this->uuid), 0700, true);

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $this->expectException(RestoreAttachmentSwapException::class);
            $this->service()->activate($handle, $this->uuid, $workspace, $manifest);
        } finally {
            $handle->release();
        }
    }

    public function test_activation_fails_when_staged_revalidation_fails_and_live_is_never_touched(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        file_put_contents($workspace->resolveStagedAttachmentPath('receipts/new.jpg'), 'new-content');

        // Manifest declares a hash that does not match the staged file.
        $manifest = $this->manifestFor([['path' => 'receipts/new.jpg', 'sha256' => hash('sha256', 'tampered'), 'size' => strlen('new-content')]], strlen('new-content'));

        $handle = $this->exclusiveHandle();

        try {
            $this->expectException(RestoreAttachmentValidationException::class);
            $this->service()->activate($handle, $this->uuid, $workspace, $manifest);
        } finally {
            $this->assertTrue(Storage::disk('attachments')->exists('receipts/original.jpg'));
            $this->assertFalse(is_dir($this->paths->quarantineRoot($this->uuid)));
            $handle->release();
        }
    }

    // ---- marker-durability crash windows during activate() ---------------

    public function test_marker_write_failure_before_any_rename_leaves_nothing_mutated(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $this->expectException(RestoreAttachmentSwapException::class);
            $this->service(markerWriter: $this->failingMarkerWriter())->activate($handle, $this->uuid, $workspace, $manifest);
        } finally {
            $this->assertTrue(Storage::disk('attachments')->exists('receipts/original.jpg'));
            $this->assertFalse(is_dir($this->paths->quarantineRoot($this->uuid)));
            $this->assertNull((new AttachmentSwapMarkerReader())->read($this->paths->markerPath($this->uuid), $this->uuid));
            $handle->release();
        }
    }

    public function test_marker_update_failure_after_first_rename_surfaces_manual_review_and_preserves_both_trees(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();
        // 1st write (ActivationStarted) succeeds; 2nd write (LiveQuarantined) fails.
        $markerWriter = $this->markerWriterFailingOnCall(2);

        try {
            $exception = null;

            try {
                $this->service(markerWriter: $markerWriter)->activate($handle, $this->uuid, $workspace, $manifest);
            } catch (RestoreAttachmentSwapException $e) {
                $exception = $e;
            }

            $this->assertNotNull($exception);
            $this->assertSame('marker_update_failed_after_mutation', $exception->reasonCode);

            // The rename DID succeed even though its marker update failed —
            // the original tree must still be fully recoverable in quarantine.
            $this->assertTrue(is_dir($this->paths->quarantineRoot($this->uuid)));
            $this->assertSame(
                AttachmentSwapState::InconsistentNeedsManualReview,
                $this->inspect(),
                'A mutation the marker never caught up to must never be silently read back as a normal state.',
            );
        } finally {
            $handle->release();
        }
    }

    public function test_marker_update_failure_after_second_rename_surfaces_manual_review(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();
        // 1st (ActivationStarted) and 2nd (LiveQuarantined) writes succeed; 3rd (Activated) fails.
        $markerWriter = $this->markerWriterFailingOnCall(3);

        try {
            $exception = null;

            try {
                $this->service(markerWriter: $markerWriter)->activate($handle, $this->uuid, $workspace, $manifest);
            } catch (RestoreAttachmentSwapException $e) {
                $exception = $e;
            }

            $this->assertNotNull($exception);
            $this->assertSame('marker_update_failed_after_mutation', $exception->reasonCode);

            // Both trees are fully intact — only the bookkeeping is stale.
            $this->assertTrue(Storage::disk('attachments')->exists('receipts/new.jpg'));
            $this->assertTrue(is_dir($this->paths->quarantineRoot($this->uuid)));
            $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspect());
        } finally {
            $handle->release();
        }
    }

    // ---- lock enforcement --------------------------------------------------

    public function test_activate_rejects_a_shared_handle(): void
    {
        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $shared = (new BackupSubsystemLock())->acquireShared();
        $this->assertNotNull($shared);

        try {
            $this->expectException(RestoreAttachmentSwapException::class);
            $this->service()->activate($shared, $this->uuid, $workspace, $manifest);
        } finally {
            $shared->release();
        }
    }

    public function test_activate_rejects_a_released_handle(): void
    {
        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();
        $handle->release();

        $this->expectException(RestoreAttachmentSwapException::class);
        $this->service()->activate($handle, $this->uuid, $workspace, $manifest);
    }

    public function test_activate_rejects_a_foreign_path_handle(): void
    {
        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $foreignPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-foreign-lock-'.bin2hex(random_bytes(8)).'.lock';
        $foreignHandle = (new BackupSubsystemLock($foreignPath))->acquireExclusive();
        $this->assertNotNull($foreignHandle);

        try {
            $this->expectException(RestoreAttachmentSwapException::class);
            $this->service()->activate($foreignHandle, $this->uuid, $workspace, $manifest);
        } finally {
            $foreignHandle->release();
        }
    }

    public function test_activate_does_not_create_any_additional_lock_file(): void
    {
        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            $locksDir = rtrim(Storage::disk('restores')->path('.locks'), '/\\');
            $entries = array_values(array_diff(scandir($locksDir), ['.', '..']));

            $this->assertSame(['subsystem.lock'], $entries, 'Activation must never acquire a second filesystem/Cache lock.');
        } finally {
            $handle->release();
        }
    }

    // ---- rollback() --------------------------------------------------------

    public function test_rollback_restores_the_original_attachments(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);
            $this->service()->rollback($handle, $swap);

            $this->assertTrue(Storage::disk('attachments')->exists('receipts/original.jpg'));
            $this->assertSame('original-content', Storage::disk('attachments')->get('receipts/original.jpg'));
            $this->assertFalse(Storage::disk('attachments')->exists('receipts/new.jpg'));
            $this->assertSame(AttachmentSwapState::RolledBack, $this->inspect());
        } finally {
            $handle->release();
        }
    }

    public function test_rollback_removes_the_restored_tree_only_after_the_original_is_safely_live(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);
            $this->service()->rollback($handle, $swap);

            $discard = $this->paths->rollbackDiscardRoot($this->uuid);
            $this->assertFalse(is_dir($discard), 'The discarded restored tree must be cleaned up once rollback succeeds.');
        } finally {
            $handle->release();
        }
    }

    public function test_rollback_resumes_after_a_crash_between_live_discard_and_quarantine_restore(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            // Rollback's own moves: [0] live(restored)->discard succeeds, [1]
            // quarantine->live fails, [2] the method's own best-effort recovery
            // attempt (discard->live) also fails — a genuine crash/unavailable
            // resource, not a transient error the same call recovers from.
            $crashingMover = new FakeAttachmentMoveRunner([false, true, true]);

            $threw = false;

            try {
                $this->service($crashingMover)->rollback($handle, $swap);
            } catch (RestoreAttachmentRollbackException) {
                $threw = true;
            }

            $this->assertTrue($threw, 'The simulated crash was expected to surface as a rollback failure.');
            $this->assertSame(AttachmentSwapState::InterruptedDuringRollback, $this->inspect());

            // A fresh call (as a future recovery flow would make) with a
            // working mover must be able to resume and complete successfully.
            $this->service()->rollback($handle, $swap);

            $this->assertSame(AttachmentSwapState::RolledBack, $this->inspect());
            $this->assertSame('original-content', Storage::disk('attachments')->get('receipts/original.jpg'));
        } finally {
            $handle->release();
        }
    }

    public function test_forced_rollback_failure_preserves_recoverable_data(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            // Rollback's own moves: [0] live(restored)->discard succeeds, [1] quarantine->live fails.
            $mover = new FakeAttachmentMoveRunner([false, true]);

            $this->expectException(RestoreAttachmentRollbackException::class);
            $this->service($mover)->rollback($handle, $swap);
        } finally {
            // Nothing that still holds the only copy of either tree was deleted.
            $this->assertTrue(
                is_dir($this->paths->rollbackDiscardRoot($this->uuid)) || Storage::disk('attachments')->exists('receipts/new.jpg'),
                'The restored tree must still exist somewhere recoverable.',
            );
            $this->assertTrue(is_dir($this->paths->quarantineRoot($this->uuid)) || Storage::disk('attachments')->exists('receipts/original.jpg'));
            $handle->release();
        }
    }

    public function test_marker_update_failure_after_moving_live_to_discard_surfaces_manual_review(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            // 1st write (RollbackStarted) succeeds; 2nd write (RollbackLiveDiscarded) fails.
            $markerWriter = $this->markerWriterFailingOnCall(2);

            $exception = null;

            try {
                $this->service(markerWriter: $markerWriter)->rollback($handle, $swap);
            } catch (RestoreAttachmentRollbackException $e) {
                $exception = $e;
            }

            $this->assertNotNull($exception);
            $this->assertSame('marker_update_failed_after_mutation', $exception->reasonCode);
            $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspect());
        } finally {
            $handle->release();
        }
    }

    public function test_repeated_rollback_is_idempotent(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);
            $this->service()->rollback($handle, $swap);
            $this->service()->rollback($handle, $swap);

            $this->assertTrue(Storage::disk('attachments')->exists('receipts/original.jpg'));
        } finally {
            $handle->release();
        }
    }

    public function test_rollback_before_activation_is_rejected(): void
    {
        $handle = $this->exclusiveHandle();
        $swap = AttachmentSwapHandle::forRestore($this->uuid);

        try {
            $this->expectException(RestoreAttachmentRollbackException::class);
            $this->service()->rollback($handle, $swap);
        } finally {
            $handle->release();
        }
    }

    public function test_rollback_rejects_a_shared_handle(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            $foreignPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-foreign-lock-'.bin2hex(random_bytes(8)).'.lock';
            $foreignShared = (new BackupSubsystemLock($foreignPath))->acquireShared();
            $this->assertNotNull($foreignShared);

            try {
                $this->expectException(RestoreAttachmentRollbackException::class);
                $this->service()->rollback($foreignShared, $swap);
            } finally {
                $foreignShared->release();
            }
        } finally {
            $handle->release();
        }
    }

    // ---- finalize() ---------------------------------------------------------

    public function test_finalize_removes_quarantine_and_keeps_the_restored_tree_live(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);
            $this->service()->finalize($handle, $swap);

            $this->assertFalse(is_dir($this->paths->quarantineRoot($this->uuid)));
            $this->assertTrue(Storage::disk('attachments')->exists('receipts/new.jpg'));
            $this->assertSame(AttachmentSwapState::Finalized, $this->inspect());
        } finally {
            $handle->release();
        }
    }

    public function test_double_finalize_is_safely_handled(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);
            $this->service()->finalize($handle, $swap);
            $this->service()->finalize($handle, $swap);

            $this->assertTrue(Storage::disk('attachments')->exists('receipts/new.jpg'));
        } finally {
            $handle->release();
        }
    }

    public function test_finalize_before_activation_is_rejected(): void
    {
        $handle = $this->exclusiveHandle();
        $swap = AttachmentSwapHandle::forRestore($this->uuid);

        try {
            $this->expectException(RestoreAttachmentFinalizationException::class);
            $this->service()->finalize($handle, $swap);
        } finally {
            $handle->release();
        }
    }

    public function test_finalize_failure_preserves_the_live_restored_tree_and_reports_failure(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            // Hold an open read handle on a file inside quarantine so Windows
            // refuses to delete it — deterministically forcing the deletion to
            // fail without depending on real OS permission errors.
            $quarantinedFile = $this->paths->quarantineRoot($this->uuid).DIRECTORY_SEPARATOR.'receipts'.DIRECTORY_SEPARATOR.'original.jpg';
            $openHandle = fopen($quarantinedFile, 'r');
            $this->assertNotFalse($openHandle);

            try {
                $this->expectException(RestoreAttachmentFinalizationException::class);
                $this->service()->finalize($handle, $swap);
            } finally {
                fclose($openHandle);
                $this->assertTrue(Storage::disk('attachments')->exists('receipts/new.jpg'), 'A failed finalize() must never touch the restored live tree.');
                $this->assertSame(AttachmentSwapState::Activated, $this->inspect(), 'A partial/failed deletion must never be reported as Finalized — it must remain safely retryable.');
            }
        } finally {
            $handle->release();
        }
    }

    public function test_marker_update_failure_after_quarantine_deletion_surfaces_manual_review(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            // 1st write (FinalizationStarted) succeeds; 2nd write (Finalized) fails.
            $markerWriter = $this->markerWriterFailingOnCall(2);

            $exception = null;

            try {
                $this->service(markerWriter: $markerWriter)->finalize($handle, $swap);
            } catch (RestoreAttachmentFinalizationException $e) {
                $exception = $e;
            }

            $this->assertNotNull($exception);
            $this->assertSame('marker_update_failed_after_mutation', $exception->reasonCode);
            $this->assertFalse(is_dir($this->paths->quarantineRoot($this->uuid)), 'Quarantine deletion genuinely succeeded.');
            $this->assertSame(
                AttachmentSwapState::InconsistentNeedsManualReview,
                $this->inspect(),
                'Quarantine is gone but the marker never caught up — this is never allowed to be misread as Finalized.',
            );
        } finally {
            $handle->release();
        }
    }

    public function test_finalize_rejects_a_shared_handle(): void
    {
        Storage::disk('attachments')->put('receipts/original.jpg', 'original-content');

        $workspace = $this->preparedWorkspace();
        $manifest = $this->manifestFor([$this->stageAttachment($workspace, 'receipts/new.jpg', 'new-content')], 11);

        $handle = $this->exclusiveHandle();

        try {
            $swap = $this->service()->activate($handle, $this->uuid, $workspace, $manifest);

            $foreignPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-foreign-lock-'.bin2hex(random_bytes(8)).'.lock';
            $foreignShared = (new BackupSubsystemLock($foreignPath))->acquireShared();
            $this->assertNotNull($foreignShared);

            try {
                $this->expectException(RestoreAttachmentFinalizationException::class);
                $this->service()->finalize($foreignShared, $swap);
            } finally {
                $foreignShared->release();
            }
        } finally {
            $handle->release();
        }
    }
}
