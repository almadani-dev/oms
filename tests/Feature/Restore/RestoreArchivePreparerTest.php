<?php

namespace Tests\Feature\Restore;

use App\Enums\BackupScope;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupArchiveContentVerifier;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\SecretstreamEnvelope;
use App\Services\Restore\Exceptions\RestoreArchivePreparationException;
use App\Services\Restore\RestoreActivityGuard;
use App\Services\Restore\RestoreArchiveExtractor;
use App\Services\Restore\RestoreArchivePreparer;
use App\Services\Restore\RestoreDiskSpaceEstimator;
use App\Services\Restore\RestorePreflightChecker;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Backup\BackupTestCase;
use Tests\Support\Backup\FakeProcessRunner;
use Tests\Support\Restore\FakeCurrentDatabaseSizeEstimator;
use Tests\Support\Restore\FakeFilesystemIdentity;

/**
 * OMS Task 7C.3 — RestoreArchivePreparer end to end: preflight -> private
 * workspace -> decrypt -> full verification -> safe extraction, against a
 * REAL completed+verified encrypted archive built through the existing
 * BackupCreationOrchestrator/SecretstreamEnvelope/BackupArchiveBuilder
 * (fake process runner and faked disks only — no real mysqldump, no real
 * live database or attachment mutation anywhere in this suite).
 */
class RestoreArchivePreparerTest extends BackupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());
        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');

        config([
            'oms.backup.mysql_client_path' => $this->fakeMysqlClientPath(),
            'oms.backup.restore.free_space_margin_percent' => 20,
            'oms.backup.restore.min_free_space_reserve_bytes' => 1,
        ]);
    }

    private function fakeMysqlClientPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-fake-mysql-'.uniqid('', true);
        file_put_contents($path, '#!/bin/sh');
        @chmod($path, 0755);

        return $path;
    }

    private function createCompletedBackup(BackupScope $scope = BackupScope::Full): BackupOperation
    {
        $orchestrator = $this->app->make(BackupCreationOrchestrator::class);
        $operation = $orchestrator->enqueue(BackupType::Manual, $scope, 'preparer test fixture', null);

        return $orchestrator->run($operation->id);
    }

    private function preparer(): RestoreArchivePreparer
    {
        return new RestoreArchivePreparer(
            new RestorePreflightChecker(
                new BackupKeyRing(),
                $this->app->make(SecretstreamEnvelope::class),
                new RestoreActivityGuard(),
                new RestoreDiskSpaceEstimator(new FakeCurrentDatabaseSizeEstimator(1000)),
                new FakeFilesystemIdentity(),
            ),
            new BackupKeyRing(),
            $this->app->make(SecretstreamEnvelope::class),
            new BackupArchiveContentVerifier(),
            new RestoreArchiveExtractor(),
        );
    }

    public function test_active_key_decrypt_and_full_verification_and_extraction_succeed(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);
        $restoreUuid = 'aaaaaaaa-4444-4444-4444-444444444444';

        $prepared = $this->preparer()->prepare($backup->uuid, BackupScope::Full, $restoreUuid);

        $this->assertSame($restoreUuid, $prepared->restoreUuid);
        $this->assertSame($backup->uuid, $prepared->sourceBackupUuid);
        $this->assertSame('database/dump.sql', $prepared->stagedDumpRelativePath);
        $this->assertSame(1, $prepared->stagedAttachmentsCount);
        $this->assertTrue(is_file($prepared->workspace->stagedDumpPath()));
        $this->assertTrue(is_file($prepared->workspace->resolveStagedAttachmentPath('receipts/1.jpg')));

        // Success leaves the workspace in place — cleanup transfers to a
        // later orchestrator, not this preparer.
        $this->assertTrue(Storage::disk('restores')->exists($restoreUuid.'/workspace'));
    }

    public function test_previous_key_decrypt_succeeds(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        $oldKeyId = (string) config('oms.backup.encryption.key_id');
        $oldKey = (string) config('oms.backup.encryption.key');

        config([
            'oms.backup.encryption.key_id' => 'test-key-rotated',
            'oms.backup.encryption.key' => base64_encode(str_repeat('c', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)),
            'oms.backup.encryption.previous_keys' => [$oldKeyId => $oldKey],
        ]);

        $restoreUuid = 'aaaaaaaa-4444-4444-4444-444444444445';
        $prepared = $this->preparer()->prepare($backup->uuid, BackupScope::Full, $restoreUuid);

        $this->assertSame($restoreUuid, $prepared->restoreUuid);
    }

    public function test_corrupted_ciphertext_fails_and_cleans_up_plaintext_workspace(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        $disk = Storage::disk('backups');
        $original = $disk->get((string) $backup->stored_path);
        // Flip bytes deep inside the ciphertext stream (past the cleartext
        // header) so decryption's authentication fails.
        $tampered = substr($original, 0, -20).str_repeat("\xFF", 20);
        $disk->put((string) $backup->stored_path, $tampered);

        $restoreUuid = 'aaaaaaaa-4444-4444-4444-444444444446';

        try {
            $this->preparer()->prepare($backup->uuid, BackupScope::Full, $restoreUuid);
            $this->fail('Expected RestoreArchivePreparationException.');
        } catch (RestoreArchivePreparationException $e) {
            $this->assertSame('decryption_failed', $e->reasonCode);
        }

        $this->assertFalse(Storage::disk('restores')->exists($restoreUuid.'/workspace'));
    }

    public function test_tampered_manifest_fails_before_extraction_and_cleans_up(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);

        // Decrypt the real archive out-of-band, tamper with its manifest,
        // and re-encrypt it under the same active key — simulating a
        // tampered-but-still-decryptable archive whose CONTENT no longer
        // matches its own claims.
        $envelope = $this->app->make(SecretstreamEnvelope::class);
        $keyRing = new BackupKeyRing();
        $disk = Storage::disk('backups');

        $plainPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('plain', true).'.zip';
        $envelope->decryptFile($disk->path((string) $backup->stored_path), $plainPath, fn (string $keyId): string => $keyRing->resolve($keyId));

        $zip = new \ZipArchive();
        $zip->open($plainPath);
        $manifestJson = $zip->getFromName('manifest.json');
        $manifest = json_decode($manifestJson, true);
        $manifest['backup_uuid'] = 'a-completely-different-uuid';
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        $activeKey = $keyRing->activeKey();
        $reEncryptedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('re-enc', true).'.enc';
        $envelope->encryptFile($plainPath, $reEncryptedPath, $activeKey['key_id'], $activeKey['key']);

        $disk->put((string) $backup->stored_path, file_get_contents($reEncryptedPath));
        @unlink($plainPath);
        @unlink($reEncryptedPath);

        $restoreUuid = 'aaaaaaaa-4444-4444-4444-444444444447';

        try {
            $this->preparer()->prepare($backup->uuid, BackupScope::Full, $restoreUuid);
            $this->fail('Expected RestoreArchivePreparationException.');
        } catch (RestoreArchivePreparationException $e) {
            $this->assertSame('verification_failed', $e->reasonCode);
        }

        $this->assertFalse(Storage::disk('restores')->exists($restoreUuid.'/workspace'));
    }

    public function test_source_backup_archive_is_never_modified_by_a_failed_preparation(): void
    {
        $backup = $this->createCompletedBackup(BackupScope::Full);
        $disk = Storage::disk('backups');
        $originalBytes = $disk->get((string) $backup->stored_path);
        $originalChecksum = hash('sha256', $originalBytes);

        $tampered = substr($originalBytes, 0, -10).str_repeat("\x00", 10);
        $disk->put((string) $backup->stored_path, $tampered);
        $tamperedChecksum = hash('sha256', $disk->get((string) $backup->stored_path));

        try {
            $this->preparer()->prepare($backup->uuid, BackupScope::Full, 'aaaaaaaa-4444-4444-4444-444444444448');
        } catch (RestoreArchivePreparationException) {
            // expected
        }

        // The (already-tampered-by-the-test, not by the preparer) archive
        // bytes are exactly what they were before prepare() ran.
        $this->assertSame($tamperedChecksum, hash('sha256', $disk->get((string) $backup->stored_path)));
    }
}
