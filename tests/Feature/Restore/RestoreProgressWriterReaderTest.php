<?php

namespace Tests\Feature\Restore;

use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSigner;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * OMS Task 7C.2 — real filesystem round trips against Storage::fake('restores')
 * (a real temp directory, never an in-memory mock), covering the signed,
 * atomic progress-file protocol end to end.
 */
class RestoreProgressWriterReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('restores');

        config([
            'oms.backup.restore.disk' => 'restores',
            'oms.backup.restore.progress_schema_version' => 1,
        ]);
    }

    private function snapshot(string $uuid, array $overrides = []): RestoreProgressSnapshot
    {
        $a = array_merge([
            'requestedBy' => ['user_id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            'requestedAt' => '2026-07-23T10:00:00+00:00',
            'reason' => 'Test restore',
            'scope' => 'full',
            'sourceBackupUuid' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            'preRestoreSafetyBackupUuid' => null,
            'phase' => 'validating',
            'phaseHistory' => [],
            'lastHeartbeatAt' => '2026-07-23T10:00:05+00:00',
            'result' => null,
            'restoreFailedPhase' => null,
            'errorSummary' => null,
        ], $overrides);

        return RestoreProgressSnapshot::create(
            $uuid,
            $a['requestedBy'],
            $a['requestedAt'],
            $a['reason'],
            $a['scope'],
            $a['sourceBackupUuid'],
            $a['preRestoreSafetyBackupUuid'],
            $a['phase'],
            $a['phaseHistory'],
            $a['lastHeartbeatAt'],
            $a['result'],
            $a['restoreFailedPhase'],
            $a['errorSummary'],
        );
    }

    // ---- valid round trip ------------------------------------------------------------------

    public function test_a_written_snapshot_reads_back_identically(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000001';
        $written = $this->snapshot($uuid, ['reason' => 'round trip test']);

        (new RestoreProgressWriter())->write($written);
        $read = (new RestoreProgressReader())->read($uuid);

        $this->assertSame($written->toCanonicalArray(), $read->toCanonicalArray());
    }

    public function test_progress_json_is_a_real_file_on_the_restores_disk(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000002';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $this->assertTrue(Storage::disk('restores')->exists("{$uuid}/progress.json"));
    }

    public function test_the_persisted_file_contains_a_signature_field(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000003';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        $raw = Storage::disk('restores')->get("{$uuid}/progress.json");
        $decoded = json_decode($raw, true);

        $this->assertArrayHasKey('signature', $decoded);
        $this->assertIsString($decoded['signature']);
        $this->assertNotSame('', $decoded['signature']);
    }

    // ---- rejection cases --------------------------------------------------------------------

    public function test_tampered_content_is_rejected(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000004';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['reason' => 'original']));

        $disk = Storage::disk('restores');
        $raw = $disk->get("{$uuid}/progress.json");
        $decoded = json_decode($raw, true);
        $decoded['reason'] = 'tampered'; // signature no longer matches
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->read($uuid);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000005';
        Storage::disk('restores')->put("{$uuid}/progress.json", json_encode(['restore_uuid' => $uuid, 'schema_version' => 1]));

        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->read($uuid);
    }

    public function test_malformed_json_is_rejected(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000006';
        Storage::disk('restores')->put("{$uuid}/progress.json", '{not valid json');

        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->read($uuid);
    }

    public function test_unsupported_schema_version_is_rejected(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000007';
        (new RestoreProgressWriter())->write($this->snapshot($uuid));

        // A later read expecting a different current schema version must
        // reject the (validly signed, for the OLD version) file rather
        // than silently accepting it.
        config(['oms.backup.restore.progress_schema_version' => 2]);

        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->read($uuid);
    }

    public function test_uuid_mismatch_is_rejected(): void
    {
        $original = 'aaaaaaaa-0000-0000-0000-000000000008';
        $decoy = 'aaaaaaaa-0000-0000-0000-000000000009';

        (new RestoreProgressWriter())->write($this->snapshot($original));

        $disk = Storage::disk('restores');
        // Copy a validly-signed file (still internally claiming
        // restore_uuid = $original) into a directory named $decoy.
        $disk->copy("{$original}/progress.json", "{$decoy}/progress.json");

        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->read($decoy);
    }

    public function test_oversized_raw_content_is_rejected_without_ever_json_decoding_it(): void
    {
        $huge = str_repeat('a', 300000);

        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->parse($huge, 'aaaaaaaa-0000-0000-0000-000000000010');
    }

    public function test_a_missing_progress_file_is_rejected(): void
    {
        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->read('aaaaaaaa-0000-0000-0000-000000000011');
    }

    // ---- bounded structure end to end -------------------------------------------------------

    public function test_exactly_the_maximum_phase_history_round_trips_correctly(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000012';
        $entries = [];

        for ($i = 0; $i < RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES; $i++) {
            $entries[] = ['phase' => 'validating', 'at' => '2026-07-23T10:00:00+00:00'];
        }

        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['phaseHistory' => $entries]));
        $read = (new RestoreProgressReader())->read($uuid);

        $this->assertCount(RestoreProgressSnapshot::MAX_PHASE_HISTORY_ENTRIES, $read->phaseHistory);
    }

    // ---- atomic write / temp file cleanup ---------------------------------------------------

    /**
     * Fully portable failure-injection: a plain file where a directory
     * needs to be — fopen() for the temp file "inside" it fails on every
     * OS (you cannot create a file inside a plain file), independent of
     * permission bits, which differ too much between Windows and Linux to
     * rely on for this specific case.
     */
    public function test_temp_file_is_cleaned_up_when_the_write_fails(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000013';
        $disk = Storage::disk('restores');
        $disk->put($uuid, 'not a directory');

        try {
            (new RestoreProgressWriter())->write($this->snapshot($uuid));
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            // expected
        }

        $leftovers = collect($disk->allFiles())->filter(fn (string $f): bool => str_contains($f, '.tmp'));
        $this->assertCount(0, $leftovers, 'No temp file should remain after a failed write: '.$leftovers->implode(', '));
    }

    /**
     * Windows-specific failure-injection (documented, gracefully skipped
     * elsewhere): chmod() on a FILE reliably sets Windows' read-only
     * attribute, and Windows' rename()/MoveFileEx refuses to overwrite a
     * read-only destination — this lets us deterministically force the
     * writer's final rename() to fail without adding a test-only seam to
     * production code. On Linux, rename(2) does not consult the
     * destination file's own permission bits at all, so this specific
     * mechanism would not reproduce a failure there (the atomic-write
     * *contract* itself — never touch the current file before the
     * replacement is fully written and ready — is OS-independent and
     * already exercised by every other test in this class; only this one
     * failure-injection technique is Windows-specific).
     */
    public function test_a_failed_rename_preserves_the_previous_valid_progress_file(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000014';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['reason' => 'first write']));

        $disk = Storage::disk('restores');
        $progressPath = $disk->path("{$uuid}/progress.json");
        @chmod($progressPath, 0444);

        $threw = false;

        try {
            (new RestoreProgressWriter())->write($this->snapshot($uuid, ['reason' => 'second write']));
        } catch (RuntimeException) {
            $threw = true;
        } finally {
            @chmod($progressPath, 0666);
        }

        if (! $threw) {
            $this->markTestSkipped('This environment did not honor the read-only rename-block technique used to force a write failure.');
        }

        $snapshot = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('first write', $snapshot->reason, 'The previous valid progress file must survive a failed write.');

        $leftovers = collect($disk->files($uuid))->filter(fn (string $f): bool => str_contains($f, '.tmp'));
        $this->assertCount(0, $leftovers, 'No temp file should remain after a failed write.');
    }

    public function test_previous_snapshot_is_retained_alongside_the_current_one(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000015';
        $disk = Storage::disk('restores');

        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['reason' => 'first']));
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['reason' => 'second']));

        $this->assertTrue($disk->exists("{$uuid}/progress.previous.json"));

        $previousRaw = json_decode($disk->get("{$uuid}/progress.previous.json"), true);
        $this->assertSame('first', $previousRaw['reason']);

        $currentSnapshot = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('second', $currentSnapshot->reason);
    }

    // ---- canonical signing determinism -------------------------------------------------------

    public function test_signing_the_same_canonical_body_twice_produces_the_same_signature(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000016';
        $snapshot = $this->snapshot($uuid);

        $bodyA = array_merge(['schema_version' => 1], $snapshot->toCanonicalArray());
        $bodyB = array_merge(['schema_version' => 1], $snapshot->toCanonicalArray());

        $jsonA = json_encode($bodyA, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $jsonB = json_encode($bodyB, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame($jsonA, $jsonB);
        $this->assertSame(RestoreProgressSigner::sign($jsonA), RestoreProgressSigner::sign($jsonB));
    }
}
