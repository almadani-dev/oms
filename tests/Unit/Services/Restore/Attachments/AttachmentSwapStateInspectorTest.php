<?php

namespace Tests\Unit\Services\Restore\Attachments;

use App\Services\Restore\Attachments\AttachmentSwapMarker;
use App\Services\Restore\Attachments\AttachmentSwapMarkerWriter;
use App\Services\Restore\Attachments\AttachmentSwapPhase;
use App\Services\Restore\Attachments\AttachmentSwapState;
use App\Services\Restore\Attachments\AttachmentSwapStateInspector;
use App\Services\Restore\Attachments\RestoreAttachmentPaths;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — AttachmentSwapStateInspector:
 * read-only crash-state inspection derived from directory existence plus the
 * SIGNED swap marker's verified phase. Never mutates the filesystem. A
 * tampered/unsigned/UUID-mismatched/unsupported marker always resolves to
 * InconsistentNeedsManualReview, regardless of what the directories look
 * like — proven directly here, not merely asserted.
 */
class AttachmentSwapStateInspectorTest extends TestCase
{
    private const UUID = 'aaaaaaaa-1111-1111-1111-111111111111';

    private const OTHER_UUID = 'bbbbbbbb-2222-2222-2222-222222222222';

    private RestoreAttachmentPaths $paths;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        $this->paths = new RestoreAttachmentPaths();
        $this->cleanupSwapArtifacts();
    }

    protected function tearDown(): void
    {
        $this->cleanupSwapArtifacts();

        parent::tearDown();
    }

    /**
     * Storage::fake('attachments') only resets the 'attachments' disk's own
     * root — the sibling quarantine/discard/marker paths this class
     * computes live one level up and are NOT reset by it, so leftover state
     * from an earlier test in the same process would otherwise leak into
     * the next one using the same UUID(s).
     */
    private function cleanupSwapArtifacts(): void
    {
        foreach ([self::UUID, self::OTHER_UUID] as $uuid) {
            $this->removeDirectory($this->paths->quarantineRoot($uuid));
            $this->removeDirectory($this->paths->rollbackDiscardRoot($uuid));

            $marker = $this->paths->markerPath($uuid);

            if (is_file($marker)) {
                unlink($marker);
            }
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

    private function inspector(): AttachmentSwapStateInspector
    {
        return new AttachmentSwapStateInspector();
    }

    private function writeMarker(AttachmentSwapPhase $phase, string $uuid = self::UUID): void
    {
        (new AttachmentSwapMarkerWriter())->write(
            AttachmentSwapMarker::create($uuid, $phase),
            $this->paths->markerPath($uuid),
        );
    }

    private function mkQuarantine(): void
    {
        mkdir($this->paths->quarantineRoot(self::UUID), 0700, true);
    }

    private function mkDiscard(): void
    {
        mkdir($this->paths->rollbackDiscardRoot(self::UUID), 0700, true);
    }

    private function rmLive(): void
    {
        rmdir($this->paths->liveRoot());
    }

    // ---- each defined state recognized ----------------------------------

    public function test_fresh_state_with_no_marker_and_no_quarantine_is_not_activated(): void
    {
        $this->assertSame(AttachmentSwapState::NotActivated, $this->inspector()->inspect(self::UUID));
    }

    public function test_activation_started_marker_with_nothing_mutated_yet_is_not_activated(): void
    {
        // Live already exists (Storage::fake creates it), quarantine/discard absent.
        $this->writeMarker(AttachmentSwapPhase::ActivationStarted);

        $this->assertSame(AttachmentSwapState::NotActivated, $this->inspector()->inspect(self::UUID));
    }

    public function test_live_quarantined_marker_with_live_missing_is_interrupted_during_activation(): void
    {
        $this->rmLive();
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::LiveQuarantined);

        $this->assertSame(AttachmentSwapState::InterruptedDuringActivation, $this->inspector()->inspect(self::UUID));
    }

    public function test_activated_marker_with_quarantine_and_live_present_is_activated(): void
    {
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::Activated);

        $this->assertSame(AttachmentSwapState::Activated, $this->inspector()->inspect(self::UUID));
    }

    public function test_rollback_started_resuming_from_activated_facts_is_activated(): void
    {
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::RollbackStarted);

        $this->assertSame(AttachmentSwapState::Activated, $this->inspector()->inspect(self::UUID));
    }

    public function test_rollback_started_resuming_from_interrupted_activation_facts_is_interrupted_during_activation(): void
    {
        $this->rmLive();
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::RollbackStarted);

        $this->assertSame(AttachmentSwapState::InterruptedDuringActivation, $this->inspector()->inspect(self::UUID));
    }

    public function test_rollback_live_discarded_with_discard_present_is_interrupted_during_rollback(): void
    {
        $this->rmLive();
        $this->mkQuarantine();
        $this->mkDiscard();
        $this->writeMarker(AttachmentSwapPhase::RollbackLiveDiscarded);

        $this->assertSame(AttachmentSwapState::InterruptedDuringRollback, $this->inspector()->inspect(self::UUID));
    }

    public function test_rollback_live_discarded_with_no_discard_ever_created_is_interrupted_during_activation(): void
    {
        $this->rmLive();
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::RollbackLiveDiscarded);

        $this->assertSame(AttachmentSwapState::InterruptedDuringActivation, $this->inspector()->inspect(self::UUID));
    }

    public function test_rolled_back_marker_with_no_quarantine_and_live_present_is_rolled_back(): void
    {
        $this->writeMarker(AttachmentSwapPhase::RolledBack);

        $this->assertSame(AttachmentSwapState::RolledBack, $this->inspector()->inspect(self::UUID));
    }

    public function test_rolled_back_marker_tolerates_a_lingering_discard_tree(): void
    {
        $this->mkDiscard();
        $this->writeMarker(AttachmentSwapPhase::RolledBack);

        $this->assertSame(AttachmentSwapState::RolledBack, $this->inspector()->inspect(self::UUID));
    }

    public function test_finalization_started_with_quarantine_still_intact_is_activated(): void
    {
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::FinalizationStarted);

        $this->assertSame(AttachmentSwapState::Activated, $this->inspector()->inspect(self::UUID));
    }

    public function test_finalized_marker_with_no_quarantine_and_live_present_is_finalized(): void
    {
        $this->writeMarker(AttachmentSwapPhase::Finalized);

        $this->assertSame(AttachmentSwapState::Finalized, $this->inspector()->inspect(self::UUID));
    }

    public function test_not_activated_and_finalized_are_distinguishable_only_because_of_the_marker(): void
    {
        // Identical directory layout in both cases (live exists, no
        // quarantine, no discard) — the marker is the ONLY thing that tells
        // these two apart.
        $this->assertSame(AttachmentSwapState::NotActivated, $this->inspector()->inspect(self::UUID));

        $this->writeMarker(AttachmentSwapPhase::Finalized);

        $this->assertSame(AttachmentSwapState::Finalized, $this->inspector()->inspect(self::UUID));
    }

    // ---- ambiguous / impossible combinations -> manual review -----------

    public function test_quarantine_directory_without_any_marker_is_inconsistent(): void
    {
        $this->mkQuarantine();

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_discard_directory_without_any_marker_is_inconsistent(): void
    {
        $this->mkDiscard();

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_activation_started_marker_with_quarantine_already_present_is_inconsistent(): void
    {
        // Impossible under the write-ahead protocol: the rename can never
        // race ahead of its own "about to mutate" marker.
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::ActivationStarted);

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_activated_marker_with_missing_live_is_inconsistent(): void
    {
        $this->rmLive();
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::Activated);

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_rolled_back_marker_with_quarantine_still_present_is_inconsistent(): void
    {
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::RolledBack);

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_finalized_marker_with_quarantine_still_present_is_inconsistent(): void
    {
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::Finalized);

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_both_quarantine_and_discard_present_simultaneously_is_inconsistent(): void
    {
        $this->mkQuarantine();
        $this->mkDiscard();
        $this->writeMarker(AttachmentSwapPhase::Activated);

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    // ---- marker integrity: tampering never yields a normal state --------

    public function test_corrupt_json_marker_is_inconsistent(): void
    {
        file_put_contents($this->paths->markerPath(self::UUID), 'not valid json{{{');
        $this->mkQuarantine();

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_unsigned_marker_is_inconsistent(): void
    {
        $payload = json_encode(['restore_uuid' => self::UUID, 'phase' => 'activated', 'updated_at' => gmdate(DATE_ATOM)]);
        file_put_contents($this->paths->markerPath(self::UUID), $payload);
        $this->mkQuarantine();

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_tampered_signature_is_inconsistent(): void
    {
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::Activated);

        $markerPath = $this->paths->markerPath(self::UUID);
        $decoded = json_decode(file_get_contents($markerPath), true);
        $decoded['phase'] = 'finalized'; // tamper with content, keep the old signature
        file_put_contents($markerPath, json_encode($decoded));

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_uuid_mismatched_marker_is_inconsistent(): void
    {
        $this->mkQuarantine();
        // Write a validly-signed marker but for a DIFFERENT restore UUID at
        // this UUID's own marker path.
        $marker = AttachmentSwapMarker::create(self::OTHER_UUID, AttachmentSwapPhase::Activated);
        (new AttachmentSwapMarkerWriter())->write($marker, $this->paths->markerPath(self::UUID));

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_unsupported_phase_value_is_inconsistent(): void
    {
        $body = ['restore_uuid' => self::UUID, 'phase' => 'not_a_real_phase', 'updated_at' => gmdate(DATE_ATOM)];
        $canonical = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body['signature'] = hash_hmac('sha256', $canonical, hash_hmac('sha256', 'oms-restore-attachment-swap-v1', (string) config('app.key')));
        file_put_contents($this->paths->markerPath(self::UUID), json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->mkQuarantine();

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    public function test_marker_with_extra_unexpected_key_is_inconsistent(): void
    {
        $body = ['restore_uuid' => self::UUID, 'phase' => 'activated', 'updated_at' => gmdate(DATE_ATOM), 'extra_field' => 'unexpected'];
        $canonical = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body['signature'] = hash_hmac('sha256', $canonical, hash_hmac('sha256', 'oms-restore-attachment-swap-v1', (string) config('app.key')));
        file_put_contents($this->paths->markerPath(self::UUID), json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->mkQuarantine();

        $this->assertSame(AttachmentSwapState::InconsistentNeedsManualReview, $this->inspector()->inspect(self::UUID));
    }

    // ---- no automatic destructive repair ---------------------------------

    public function test_inspection_never_mutates_the_filesystem(): void
    {
        $this->mkQuarantine();
        $this->writeMarker(AttachmentSwapPhase::Activated);

        $this->inspector()->inspect(self::UUID);
        $this->inspector()->inspect(self::UUID);

        $this->assertTrue(is_dir($this->paths->liveRoot()));
        $this->assertTrue(is_dir($this->paths->quarantineRoot(self::UUID)));

        $rawBefore = file_get_contents($this->paths->markerPath(self::UUID));
        $this->inspector()->inspect(self::UUID);
        $this->assertSame($rawBefore, file_get_contents($this->paths->markerPath(self::UUID)));
    }

    public function test_inspection_never_repairs_an_inconsistent_marker(): void
    {
        $this->mkQuarantine();

        $this->inspector()->inspect(self::UUID);

        $this->assertFalse(is_file($this->paths->markerPath(self::UUID)));
        $this->assertTrue(is_dir($this->paths->quarantineRoot(self::UUID)));
    }
}
