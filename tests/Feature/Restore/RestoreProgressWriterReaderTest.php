<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Services\Restore\Exceptions\RestoreProgressIntegrityException;
use App\Services\Restore\Exceptions\RestoreProgressWriteException;
use App\Services\Restore\Metadata\BackupOperationSnapshot;
use App\Services\Restore\Metadata\RestoreOperationSnapshot;
use App\Services\Restore\Metadata\RestoreReconciliationSnapshot;
use App\Services\Restore\RestoreProgressReader;
use App\Services\Restore\RestoreProgressSigner;
use App\Services\Restore\RestoreProgressSnapshot;
use App\Services\Restore\RestoreProgressWriter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Restore\FakeRestoreProgressDurability;
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
            'reconciliationSnapshot' => null,
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
            $a['reconciliationSnapshot'],
        );
    }

    private function reconciliationSnapshot(): RestoreReconciliationSnapshot
    {
        $now = now()->format(\DATE_ATOM);
        $identity = ['user_id' => null, 'name' => 'Admin', 'email' => 'admin@example.com'];

        $source = BackupOperationSnapshot::create(
            uuid: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            type: 'manual',
            scope: BackupScope::Full->value,
            disk: 'backups',
            archivePath: 'source.enc',
            archiveFilename: 'source.enc',
            sizeBytes: 100,
            checksumSha256: str_repeat('a', 64),
            encryptionKeyId: 'key-1',
            manifestVersion: 1,
            fileCount: 2,
            originalSizeBytes: 200,
            createdAt: $now,
            startedAt: $now,
            completedAt: $now,
            verifiedAt: $now,
            createdBy: $identity,
            isProtected: false,
            operationReason: null,
        );

        $safety = BackupOperationSnapshot::create(
            uuid: 'cccccccc-dddd-eeee-ffff-000000000000',
            type: 'pre_restore',
            scope: BackupScope::Full->value,
            disk: 'backups',
            archivePath: 'safety.enc',
            archiveFilename: 'safety.enc',
            sizeBytes: 100,
            checksumSha256: str_repeat('b', 64),
            encryptionKeyId: 'key-1',
            manifestVersion: 1,
            fileCount: 2,
            originalSizeBytes: 200,
            createdAt: $now,
            startedAt: $now,
            completedAt: $now,
            verifiedAt: $now,
            createdBy: $identity,
            isProtected: true,
            operationReason: 'pre-restore safety backup',
        );

        $restore = RestoreOperationSnapshot::create(
            restoreUuid: 'aaaaaaaa-0000-0000-0000-000000000001',
            sourceUuid: 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
            safetyUuid: 'cccccccc-dddd-eeee-ffff-000000000000',
            scope: BackupScope::Full->value,
            requestedBy: $identity,
            reason: 'testing',
            confirmedAt: $now,
            startedAt: $now,
            phaseHistory: [['phase' => 'database_restoring', 'at' => $now]],
            resultContext: null,
        );

        return RestoreReconciliationSnapshot::create($source, $safety, $restore);
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

    // ---- OMS Task 7C.5 correction pass: optional reconciliation snapshot -------------------

    public function test_a_written_snapshot_with_a_reconciliation_snapshot_reads_back_identically(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-00000000001d';
        $written = $this->snapshot($uuid, ['reconciliationSnapshot' => $this->reconciliationSnapshot()]);

        (new RestoreProgressWriter())->write($written);
        $read = (new RestoreProgressReader())->read($uuid);

        $this->assertNotNull($read->reconciliationSnapshot);
        $this->assertEquals($written->reconciliationSnapshot, $read->reconciliationSnapshot);
        $this->assertSame($written->toCanonicalArray(), $read->toCanonicalArray());
    }

    public function test_tampering_with_a_reconciliation_snapshot_field_invalidates_the_signature(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-00000000001e';
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['reconciliationSnapshot' => $this->reconciliationSnapshot()]));

        $disk = Storage::disk('restores');
        $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
        $decoded['reconciliation_snapshot']['source_backup']['checksum_sha256'] = str_repeat('f', 64);
        $disk->put("{$uuid}/progress.json", json_encode($decoded));

        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->read($uuid);
    }

    public function test_a_malformed_reconciliation_snapshot_is_rejected_on_read(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-00000000001f';
        $written = $this->snapshot($uuid);

        $body = array_merge(['schema_version' => 1], $written->toCanonicalArray());
        // A structurally invalid nested snapshot (missing required keys) —
        // simulates a corrupted/malicious file, never something this
        // codebase's own writer could ever produce (RestoreReconciliationSnapshot
        // cannot be constructed in this shape). The whole body is signed
        // exactly as-is, so this fails CONTENT validation, not the
        // signature check — isolating exactly the guarantee under test.
        $body['reconciliation_snapshot'] = ['source_backup' => ['uuid' => 'not-a-uuid']];

        $canonicalJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body['signature'] = RestoreProgressSigner::sign($canonicalJson);

        Storage::disk('restores')->put("{$uuid}/progress.json", json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->expectException(RestoreProgressIntegrityException::class);
        (new RestoreProgressReader())->read($uuid);
    }

    public function test_an_old_progress_payload_without_a_reconciliation_snapshot_key_still_reads_successfully(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000020';
        $written = $this->snapshot($uuid);

        // Simulates a file written before this field existed at all — the
        // key is completely absent, not merely null.
        $body = array_merge(['schema_version' => 1], $written->toCanonicalArray());
        unset($body['reconciliation_snapshot']);

        $canonicalJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body['signature'] = RestoreProgressSigner::sign($canonicalJson);

        Storage::disk('restores')->put("{$uuid}/progress.json", json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $read = (new RestoreProgressReader())->read($uuid);

        $this->assertNull($read->reconciliationSnapshot);
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

    // ---- durability hardening: fsync gate + parent-directory sync ---------------------------

    public function test_a_temp_file_sync_failure_prevents_the_rename_and_preserves_the_current_file(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000017';

        // Establish a valid current progress.json with a fully-durable write.
        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['reason' => 'durable original']));

        // Now attempt a second write whose temp-file sync fails.
        $failingDurability = new FakeRestoreProgressDurability(syncFileResult: false);

        try {
            (new RestoreProgressWriter($failingDurability))->write($this->snapshot($uuid, ['reason' => 'should never land']));
            $this->fail('Expected RestoreProgressWriteException.');
        } catch (RestoreProgressWriteException) {
            // expected
        }

        // The current authoritative file must be completely unchanged.
        $snapshot = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('durable original', $snapshot->reason, 'A temp-file sync failure must never replace the current valid progress.json.');
    }

    public function test_a_temp_file_sync_failure_cleans_up_the_temp_file(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000018';
        $failingDurability = new FakeRestoreProgressDurability(syncFileResult: false);

        try {
            (new RestoreProgressWriter($failingDurability))->write($this->snapshot($uuid));
            $this->fail('Expected RestoreProgressWriteException.');
        } catch (RestoreProgressWriteException) {
            // expected
        }

        $disk = Storage::disk('restores');
        $leftovers = collect($disk->exists($uuid) ? $disk->files($uuid) : [])
            ->filter(fn (string $f): bool => str_contains($f, '.tmp'));

        $this->assertCount(0, $leftovers, 'No temp file should remain after a durability failure.');
        $this->assertFalse($disk->exists("{$uuid}/progress.json"), 'No progress.json should have been published on a durability failure.');
    }

    public function test_a_temp_file_sync_failure_never_calls_the_parent_directory_sync(): void
    {
        // Proves the rename (and therefore the post-rename directory sync)
        // is never reached when the temp-file durability gate fails.
        $uuid = 'aaaaaaaa-0000-0000-0000-000000000019';
        $failingDurability = new FakeRestoreProgressDurability(syncFileResult: false);

        try {
            (new RestoreProgressWriter($failingDurability))->write($this->snapshot($uuid));
            $this->fail('Expected RestoreProgressWriteException.');
        } catch (RestoreProgressWriteException) {
            // expected
        }

        $this->assertSame(1, $failingDurability->syncFileCalls);
        $this->assertSame(0, $failingDurability->syncDirectoryCalls, 'Parent-directory sync must never run when the temp-file sync failed.');
    }

    public function test_parent_directory_sync_is_attempted_only_after_a_successful_rename(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-00000000001a';
        $disk = Storage::disk('restores');

        $durability = new FakeRestoreProgressDurability();
        // Capture filesystem state at the exact moment the directory sync is
        // attempted — progress.json must ALREADY be the published new file,
        // proving the sync happens strictly after the rename.
        $reasonAtSyncTime = null;
        $durability->onSyncDirectory = function () use ($disk, $uuid, &$reasonAtSyncTime): void {
            if ($disk->exists("{$uuid}/progress.json")) {
                $decoded = json_decode($disk->get("{$uuid}/progress.json"), true);
                $reasonAtSyncTime = $decoded['reason'] ?? null;
            }
        };

        (new RestoreProgressWriter($durability))->write($this->snapshot($uuid, ['reason' => 'published before sync']));

        $this->assertSame(1, $durability->syncFileCalls);
        $this->assertSame(1, $durability->syncDirectoryCalls);
        $this->assertSame('published before sync', $reasonAtSyncTime, 'The parent-directory sync must run only after progress.json is the published new file.');
    }

    public function test_a_parent_directory_sync_failure_does_not_corrupt_the_published_file(): void
    {
        $uuid = 'aaaaaaaa-0000-0000-0000-00000000001b';

        // Directory sync reports failure — per the documented best-effort
        // policy the write still succeeds and progress.json is the correct
        // new content (the rename already completed).
        $durability = new FakeRestoreProgressDurability(syncFileResult: true, syncDirectoryResult: false);

        (new RestoreProgressWriter($durability))->write($this->snapshot($uuid, ['reason' => 'survives dir-sync failure']));

        $snapshot = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('survives dir-sync failure', $snapshot->reason, 'A best-effort parent-directory sync failure must never corrupt or abort the published progress.json.');
    }

    public function test_a_flush_and_sync_success_publishes_the_file_with_the_native_durability(): void
    {
        // Sanity: the default (native) durability path completes end to end
        // on this runtime, i.e. the real fsync gate does not spuriously
        // reject a genuine write.
        $uuid = 'aaaaaaaa-0000-0000-0000-00000000001c';

        (new RestoreProgressWriter())->write($this->snapshot($uuid, ['reason' => 'native durable']));

        $snapshot = (new RestoreProgressReader())->read($uuid);
        $this->assertSame('native durable', $snapshot->reason);
    }
}
