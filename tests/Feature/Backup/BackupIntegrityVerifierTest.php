<?php

namespace Tests\Feature\Backup;

use App\Enums\BackupScope;
use App\Enums\BackupType;
use App\Models\BackupOperation;
use App\Services\Backup\BackupCreationOrchestrator;
use App\Services\Backup\BackupFileLock;
use App\Services\Backup\BackupIntegrityVerifier;
use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\Exceptions\BackupIntegrityException;
use App\Services\Backup\SecretstreamEnvelope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Backup\FakeProcessRunner;
use ZipArchive;

/**
 * Covers the OMS Task 7B.1 "INTEGRITY" test category (items 43-48). Every
 * backup used here is created through the exact same orchestrator/fake
 * process pipeline as BackupCreationOrchestratorTest — no real mysqldump,
 * no real attachment reads.
 */
class BackupIntegrityVerifierTest extends BackupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeMysqlConnection();
        $this->bindFakeProcessRunner(new FakeProcessRunner());

        Storage::disk('attachments')->put('receipts/1.jpg', 'attachment content');
    }

    private function completedOperation(BackupScope $scope = BackupScope::Full): BackupOperation
    {
        $orchestrator = $this->app->make(BackupCreationOrchestrator::class);
        $operation = $orchestrator->enqueue(BackupType::Manual, $scope, null, null);

        return $orchestrator->run($operation->id);
    }

    private function verifier(): BackupIntegrityVerifier
    {
        return $this->app->make(BackupIntegrityVerifier::class);
    }

    /**
     * Decrypts the operation's current encrypted archive, lets $mutateZip
     * tamper with its contents, re-encrypts with the real active key, and
     * replaces the stored file + its recorded size/checksum — isolating
     * "the encrypted container is valid but its content is wrong" from
     * "the encrypted container itself is corrupted" (a separate test).
     */
    private function tamperArchiveContent(BackupOperation $operation, callable $mutateZip): void
    {
        $disk = Storage::disk('backups');
        $absolutePath = $disk->path((string) $operation->stored_path);

        $envelope = $this->app->make(SecretstreamEnvelope::class);
        $keyRing = $this->app->make(BackupKeyRing::class);

        $plainPath = $disk->path('.work/tamper-'.Str::uuid().'.zip');
        @mkdir(dirname($plainPath), 0777, true);

        $envelope->decryptFile($absolutePath, $plainPath, fn (string $keyId): string => $keyRing->resolve($keyId));

        $zip = new ZipArchive();
        $zip->open($plainPath);
        $mutateZip($zip);
        $zip->close();

        $activeKey = $keyRing->activeKey();
        $reencryptedPath = $plainPath.'.enc';
        $envelope->encryptFile($plainPath, $reencryptedPath, $activeKey['key_id'], $activeKey['key']);

        @unlink($absolutePath);
        rename($reencryptedPath, $absolutePath);
        @unlink($plainPath);

        clearstatcache(true, $absolutePath);

        $operation->forceFill([
            'size_bytes' => filesize($absolutePath),
            'checksum_sha256' => hash_file('sha256', $absolutePath),
        ])->save();
    }

    // ---- 43. full valid backup verifies -----------------------------------------

    public function test_full_valid_backup_verifies(): void
    {
        // Since Task 7B.1's correction, BackupCreationOrchestrator itself
        // performs the full content verification before publishing and
        // already stamps verified_at at creation time — a standalone
        // re-verification of an untouched backup must still succeed and
        // must not clear or otherwise disturb that timestamp.
        $operation = $this->completedOperation();
        $this->assertNotNull($operation->verified_at);
        $firstVerifiedAt = $operation->verified_at;

        $this->verifier()->verify($operation);

        $operation->refresh();
        $this->assertNotNull($operation->verified_at);
        $this->assertTrue($operation->verified_at->greaterThanOrEqualTo($firstVerifiedAt));
    }

    // ---- 44. encrypted checksum mismatch fails ------------------------------------

    public function test_encrypted_checksum_mismatch_fails(): void
    {
        $operation = $this->completedOperation();

        $disk = Storage::disk('backups');
        $absolutePath = $disk->path((string) $operation->stored_path);
        $bytes = file_get_contents($absolutePath);
        $bytes[10] = chr(ord($bytes[10]) ^ 0xFF);
        file_put_contents($absolutePath, $bytes);

        // DB checksum intentionally left untouched — the file on disk no
        // longer matches it.
        $this->expectException(BackupIntegrityException::class);
        $this->verifier()->verify($operation);
    }

    // ---- 45. manifest mismatch fails ------------------------------------------------

    public function test_manifest_uuid_mismatch_fails(): void
    {
        $operation = $this->completedOperation();

        $this->tamperArchiveContent($operation, function (ZipArchive $zip): void {
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $manifest['backup_uuid'] = 'not-the-real-uuid';
            $zip->deleteName('manifest.json');
            $zip->addFromString('manifest.json', json_encode($manifest));
        });

        $this->expectException(BackupIntegrityException::class);
        $this->verifier()->verify($operation->fresh());
    }

    // ---- 46. attachment checksum mismatch fails --------------------------------------

    public function test_attachment_checksum_mismatch_fails(): void
    {
        $operation = $this->completedOperation();

        $this->tamperArchiveContent($operation, function (ZipArchive $zip): void {
            $zip->deleteName('files/attachments/receipts/1.jpg');
            $zip->addFromString('files/attachments/receipts/1.jpg', 'tampered content, different bytes');
        });

        $this->expectException(BackupIntegrityException::class);
        $this->verifier()->verify($operation->fresh());
    }

    public function test_unexpected_extra_archive_entry_fails(): void
    {
        $operation = $this->completedOperation();

        $this->tamperArchiveContent($operation, function (ZipArchive $zip): void {
            $zip->addFromString('files/attachments/receipts/extra-not-in-manifest.jpg', 'sneaky extra file');
        });

        $this->expectException(BackupIntegrityException::class);
        $this->verifier()->verify($operation->fresh());
    }

    public function test_missing_archive_entry_fails(): void
    {
        $operation = $this->completedOperation();

        $this->tamperArchiveContent($operation, function (ZipArchive $zip): void {
            $zip->deleteName('files/attachments/receipts/1.jpg');
        });

        $this->expectException(BackupIntegrityException::class);
        $this->verifier()->verify($operation->fresh());
    }

    // ---- 47. verified_at changes only after full success ------------------------------

    public function test_verified_at_is_unchanged_after_a_failed_verification(): void
    {
        $operation = $this->completedOperation();
        // Creation already ran full verification and stamped verified_at —
        // capture that baseline before corrupting the file, so we can
        // prove a subsequent FAILED re-verification never updates it.
        $originalVerifiedAt = $operation->verified_at;
        $this->assertNotNull($originalVerifiedAt);

        $disk = Storage::disk('backups');
        $absolutePath = $disk->path((string) $operation->stored_path);
        $bytes = file_get_contents($absolutePath);
        $bytes[5] = chr(ord($bytes[5]) ^ 0xFF);
        file_put_contents($absolutePath, $bytes);

        try {
            $this->verifier()->verify($operation);
            $this->fail('Expected BackupIntegrityException was not thrown.');
        } catch (BackupIntegrityException) {
            $operation->refresh();
            $this->assertTrue($operation->verified_at->equalTo($originalVerifiedAt));
        }
    }

    // ---- 48. verification temporary plaintext is cleaned -------------------------------

    public function test_temporary_verification_plaintext_is_cleaned_on_success_and_failure(): void
    {
        $disk = Storage::disk('backups');

        $operation = $this->completedOperation();
        $this->verifier()->verify($operation);

        $leftovers = collect($disk->allFiles())->filter(fn (string $f): bool => str_contains($f, '.work/verify-'));
        $this->assertCount(0, $leftovers, 'No verification temp file should remain after success.');

        $operationTwo = $this->completedOperation();
        $bytes = file_get_contents($disk->path((string) $operationTwo->stored_path));
        $bytes[3] = chr(ord($bytes[3]) ^ 0xFF);
        file_put_contents($disk->path((string) $operationTwo->stored_path), $bytes);

        try {
            $this->verifier()->verify($operationTwo);
        } catch (BackupIntegrityException) {
            // expected
        }

        $leftoversAfterFailure = collect($disk->allFiles())->filter(fn (string $f): bool => str_contains($f, '.work/verify-'));
        $this->assertCount(0, $leftoversAfterFailure, 'No verification temp file should remain after a failure.');
    }

    // ---- correction 3: verification cannot race deletion --------------------------------

    public function test_verification_is_blocked_while_the_per_backup_lock_is_held_externally(): void
    {
        $operation = $this->completedOperation();

        $externalLock = Cache::lock(BackupFileLock::name($operation->uuid), 60);
        $this->assertTrue($externalLock->get(), 'Test setup: expected to acquire the per-backup lock.');

        try {
            $this->expectException(BackupIntegrityException::class);
            $this->verifier()->verify($operation);
        } finally {
            $externalLock->release();
        }
    }

    // ---- OMS Task 7C.2: shared subsystem lock ------------------------------------------------

    public function test_verification_is_blocked_while_the_exclusive_subsystem_lock_is_held(): void
    {
        $operation = $this->completedOperation();

        $exclusive = (new BackupSubsystemLock())->acquireExclusive();
        $this->assertNotNull($exclusive, 'Test setup: expected to acquire the exclusive subsystem lock.');

        try {
            $this->expectException(BackupIntegrityException::class);
            $this->verifier()->verify($operation);
        } finally {
            $exclusive->release();
        }
    }
}
