<?php

namespace Tests\Unit\Services\Restore\Attachments;

use App\Services\Restore\Attachments\AttachmentSwapMarker;
use App\Services\Restore\Attachments\AttachmentSwapMarkerReader;
use App\Services\Restore\Attachments\AttachmentSwapMarkerWriter;
use App\Services\Restore\Attachments\AttachmentSwapPhase;
use App\Services\Restore\Exceptions\RestoreAttachmentMarkerException;
use Tests\Support\Restore\FakeRestoreProgressDurability;
use Tests\TestCase;

/**
 * OMS Task 7C.6 (crash-safety correction pass) — AttachmentSwapMarkerWriter/
 * Reader: signed, durable, crash-safe write; strict verified read. Mirrors
 * RestoreProgressWriter/ReaderTest's own durability-proof style, reusing the
 * identical RestoreProgressDurability seam.
 */
class AttachmentSwapMarkerWriterReaderTest extends TestCase
{
    private const UUID = 'aaaaaaaa-1111-1111-1111-111111111111';

    private string $directory;

    private string $markerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-swap-marker-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->markerPath = $this->directory.DIRECTORY_SEPARATOR.'attachments.pre_restore.'.self::UUID.'.state.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_round_trip_write_then_read(): void
    {
        $marker = AttachmentSwapMarker::create(self::UUID, AttachmentSwapPhase::Activated);
        (new AttachmentSwapMarkerWriter())->write($marker, $this->markerPath);

        $read = (new AttachmentSwapMarkerReader())->read($this->markerPath, self::UUID);

        $this->assertNotNull($read);
        $this->assertSame(self::UUID, $read->restoreUuid);
        $this->assertSame(AttachmentSwapPhase::Activated, $read->phase);
    }

    public function test_read_returns_null_when_no_marker_was_ever_written(): void
    {
        $this->assertNull((new AttachmentSwapMarkerReader())->read($this->markerPath, self::UUID));
    }

    public function test_tampered_content_fails_signature_verification(): void
    {
        $marker = AttachmentSwapMarker::create(self::UUID, AttachmentSwapPhase::Activated);
        (new AttachmentSwapMarkerWriter())->write($marker, $this->markerPath);

        $decoded = json_decode(file_get_contents($this->markerPath), true);
        $decoded['phase'] = 'finalized';
        file_put_contents($this->markerPath, json_encode($decoded));

        $this->expectException(RestoreAttachmentMarkerException::class);
        (new AttachmentSwapMarkerReader())->read($this->markerPath, self::UUID);
    }

    public function test_reading_with_a_different_expected_uuid_fails(): void
    {
        $marker = AttachmentSwapMarker::create(self::UUID, AttachmentSwapPhase::Activated);
        (new AttachmentSwapMarkerWriter())->write($marker, $this->markerPath);

        $this->expectException(RestoreAttachmentMarkerException::class);
        (new AttachmentSwapMarkerReader())->read($this->markerPath, 'bbbbbbbb-2222-2222-2222-222222222222');
    }

    public function test_fsync_failure_prevents_publishing_and_preserves_previous_valid_marker(): void
    {
        $writer = new AttachmentSwapMarkerWriter();
        $writer->write(AttachmentSwapMarker::create(self::UUID, AttachmentSwapPhase::ActivationStarted), $this->markerPath);

        $failingDurability = new FakeRestoreProgressDurability(syncFileResult: false);
        $failingWriter = new AttachmentSwapMarkerWriter($failingDurability);

        try {
            $failingWriter->write(AttachmentSwapMarker::create(self::UUID, AttachmentSwapPhase::LiveQuarantined), $this->markerPath);
            $this->fail('Expected a RestoreAttachmentMarkerException.');
        } catch (RestoreAttachmentMarkerException) {
            // expected
        }

        $stillValid = (new AttachmentSwapMarkerReader())->read($this->markerPath, self::UUID);
        $this->assertSame(AttachmentSwapPhase::ActivationStarted, $stillValid->phase, 'A failed fsync must never replace the previously-valid marker.');

        // No leftover temp file.
        $leftovers = array_filter(glob($this->directory.DIRECTORY_SEPARATOR.'*.tmp') ?: []);
        $this->assertSame([], $leftovers);
    }

    public function test_directory_sync_is_attempted_after_a_successful_rename(): void
    {
        $durability = new FakeRestoreProgressDurability();
        $capturedPhaseAtSyncTime = null;

        $durability->onSyncDirectory = function () use (&$capturedPhaseAtSyncTime): void {
            $read = (new AttachmentSwapMarkerReader())->read($this->markerPath, self::UUID);
            $capturedPhaseAtSyncTime = $read?->phase;
        };

        (new AttachmentSwapMarkerWriter($durability))->write(
            AttachmentSwapMarker::create(self::UUID, AttachmentSwapPhase::Activated),
            $this->markerPath,
        );

        $this->assertSame(1, $durability->syncDirectoryCalls);
        $this->assertSame(
            AttachmentSwapPhase::Activated,
            $capturedPhaseAtSyncTime,
            'The directory sync must be attempted AFTER the rename already published the new marker.',
        );
    }

    public function test_missing_directory_fails_closed(): void
    {
        $writer = new AttachmentSwapMarkerWriter();
        $missingDir = $this->directory.DIRECTORY_SEPARATOR.'does-not-exist'.DIRECTORY_SEPARATOR.'marker.json';

        $this->expectException(RestoreAttachmentMarkerException::class);
        $writer->write(AttachmentSwapMarker::create(self::UUID, AttachmentSwapPhase::ActivationStarted), $missingDir);
    }
}
