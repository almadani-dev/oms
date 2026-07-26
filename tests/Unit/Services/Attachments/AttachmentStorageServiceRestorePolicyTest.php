<?php

namespace Tests\Unit\Services\Attachments;

use App\Models\Attachment;
use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

/**
 * OMS Task 7C.6 — AttachmentStorageService policy review (section A of the
 * task spec).
 *
 * Finding: AttachmentStorageService itself has NO extension/MIME allowlist
 * at all — resolveDisk() only gates on Attachment::APPROVED_DISKS, and
 * isSafeRelativePath()/exists() only check path-traversal safety, never a
 * file's extension. The only "upload allowlist" anywhere in the Attachments
 * namespace is App\Services\Attachments\AttachmentUploadService's
 * ALLOWED_DIRECTORIES/ALLOWED_PREFIXES — a directory/filename-prefix rule
 * enforced at NEW-upload time only, never consulted by AttachmentStorageService
 * and never called by anything in the restore pipeline. This proves restore
 * (which never calls AttachmentUploadService) cannot be made to depend on
 * that mutable upload-time allowlist merely by AttachmentStorageService
 * changing in the future — there is no coupling to sever.
 *
 * The actual restore-specific gate is the fixed executable/script denylist
 * in RestoreAttachmentRevalidator (config('oms.backup.restore.attachments_denied_extensions')),
 * exercised in RestoreAttachmentRevalidatorTest — this file only documents
 * and proves AttachmentStorageService's own lack of such a gate.
 */
class AttachmentStorageServiceRestorePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
    }

    public function test_resolve_disk_never_inspects_file_extension_or_path(): void
    {
        $service = new AttachmentStorageService();

        $phpAttachment = new Attachment(['disk' => 'attachments', 'file_path' => 'legacy/shell.php', 'file_name' => 'shell.php']);
        $jpgAttachment = new Attachment(['disk' => 'attachments', 'file_path' => 'receipts/a.jpg', 'file_name' => 'a.jpg']);

        $this->assertNotNull($service->resolveDisk($phpAttachment));
        $this->assertNotNull($service->resolveDisk($jpgAttachment));
    }

    public function test_exists_is_not_gated_by_any_extension_allowlist(): void
    {
        Storage::disk('attachments')->put('legacy/historical.php', 'a historically valid manifest-authenticated file');

        $attachment = new Attachment(['disk' => 'attachments', 'file_path' => 'legacy/historical.php', 'file_name' => 'historical.php']);

        $this->assertTrue((new AttachmentStorageService())->exists($attachment), 'AttachmentStorageService must not reject an existing file based on its extension — it has no such rule.');
    }

    public function test_attachment_storage_service_source_never_references_the_mutable_upload_allowlist(): void
    {
        $path = (new ReflectionClass(AttachmentStorageService::class))->getFileName();
        $source = file_get_contents($path);

        $this->assertStringNotContainsString('AttachmentUploadService', $source);
        $this->assertStringNotContainsString('ALLOWED_DIRECTORIES', $source);
        $this->assertStringNotContainsString('ALLOWED_PREFIXES', $source);
    }

    public function test_disk_outside_the_approved_list_is_still_rejected_regardless_of_extension(): void
    {
        $attachment = new Attachment(['disk' => 'local', 'file_path' => 'receipts/a.jpg', 'file_name' => 'a.jpg']);

        $this->assertNull((new AttachmentStorageService())->resolveDisk($attachment));
    }
}
