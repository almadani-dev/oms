<?php

namespace Tests\Unit\Services\Restore\Attachments;

use App\Services\Restore\Attachments\RestoreAttachmentPaths;
use App\Services\Restore\Exceptions\RestoreAttachmentsException;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OMS Task 7C.6 — RestoreAttachmentPaths: UUID validation and deterministic,
 * escape-proof sibling path construction for quarantine/rollback-discard/
 * marker paths.
 */
class RestoreAttachmentPathsTest extends TestCase
{
    private const UUID = 'aaaaaaaa-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
    }

    public function test_invalid_uuid_is_rejected_for_quarantine_root(): void
    {
        $this->expectException(RestoreAttachmentsException::class);
        (new RestoreAttachmentPaths())->quarantineRoot('not-a-uuid');
    }

    public function test_invalid_uuid_is_rejected_for_rollback_discard_root(): void
    {
        $this->expectException(RestoreAttachmentsException::class);
        (new RestoreAttachmentPaths())->rollbackDiscardRoot('not-a-uuid');
    }

    public function test_invalid_uuid_is_rejected_for_marker_path(): void
    {
        $this->expectException(RestoreAttachmentsException::class);
        (new RestoreAttachmentPaths())->markerPath('not-a-uuid');
    }

    public function test_quarantine_root_is_a_sibling_of_the_live_attachments_root(): void
    {
        $paths = new RestoreAttachmentPaths();

        $live = rtrim(str_replace('\\', '/', $paths->liveRoot()), '/');
        $quarantine = str_replace('\\', '/', $paths->quarantineRoot(self::UUID));

        $this->assertSame(dirname($live), dirname($quarantine));
        $this->assertNotSame($live, $quarantine);
        $this->assertStringContainsString(self::UUID, $quarantine);
    }

    public function test_quarantine_root_never_lives_inside_the_live_attachments_root(): void
    {
        $paths = new RestoreAttachmentPaths();

        $live = rtrim(str_replace('\\', '/', $paths->liveRoot()), '/');
        $quarantine = str_replace('\\', '/', $paths->quarantineRoot(self::UUID));

        $this->assertFalse(str_starts_with($quarantine, $live.'/'));
    }

    public function test_rollback_discard_root_and_marker_path_are_distinct_from_quarantine_root(): void
    {
        $paths = new RestoreAttachmentPaths();

        $quarantine = $paths->quarantineRoot(self::UUID);
        $discard = $paths->rollbackDiscardRoot(self::UUID);
        $marker = $paths->markerPath(self::UUID);

        $this->assertNotSame($quarantine, $discard);
        $this->assertNotSame($quarantine, $marker);
        $this->assertNotSame($discard, $marker);
    }

    public function test_all_derived_paths_stay_within_the_private_root(): void
    {
        $paths = new RestoreAttachmentPaths();
        $privateRoot = rtrim(str_replace('\\', '/', $paths->privateRoot()), '/');

        foreach ([
            $paths->quarantineRoot(self::UUID),
            $paths->rollbackDiscardRoot(self::UUID),
            $paths->markerPath(self::UUID),
        ] as $path) {
            $this->assertStringStartsWith($privateRoot.'/', str_replace('\\', '/', $path));
        }
    }
}
