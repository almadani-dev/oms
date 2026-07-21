<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\GeneralExpense;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * OMS Task 6A correction #3: Attachment.file_name is a database value that
 * ends up in a Content-Disposition header - proves
 * AttachmentStorageService::safeDownloadName() strips directory segments,
 * CR/LF and other control characters, falls back to a deterministic name
 * when nothing safe is left, and that both the inline and download routes
 * actually use the sanitized value in their real HTTP response headers.
 */
class AttachmentFilenameSanitizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');

        $mysqlOnly = [
            '2026_06_24_000005_backfill_denormalized_currency_and_amounts',
            '2026_06_24_000009_add_supporting_indexes_for_financial_report',
        ];

        $paths = collect(glob(base_path('database/migrations/*.php')))
            ->reject(fn (string $path) => str_contains($path, $mysqlOnly[0]) || str_contains($path, $mysqlOnly[1]))
            ->map(fn (string $path) => 'database/migrations/'.basename($path))
            ->values()
            ->all();

        Artisan::call('migrate', [
            '--path' => $paths,
            '--realpath' => false,
            '--force' => true,
        ]);

        URL::forceRootUrl('http://localhost');

        Storage::fake('attachments');
    }

    // =========================================================
    // Service-level: safeDownloadName()
    // =========================================================

    public function test_directory_segments_are_stripped_from_the_filename(): void
    {
        $attachment = $this->makeAttachment(fileName: '../../etc/evil.jpg');

        $safe = app(AttachmentStorageService::class)->safeDownloadName($attachment);

        $this->assertSame('evil.jpg', $safe);
    }

    /**
     * PHP's own basename() is platform-dependent: it treats '\' as a
     * directory separator on Windows but NOT on Linux/macOS, so a naive
     * basename() call would leak this entire string - backslashes and all -
     * unchanged into Content-Disposition on a Linux server. Asserts the
     * exact stripped result, not just "no backslash remains", so this can't
     * silently regress back to plain basename().
     */
    public function test_windows_directory_segments_are_stripped_from_the_filename(): void
    {
        $attachment = $this->makeAttachment(fileName: '..\\..\\folder\\evil.pdf');

        $safe = app(AttachmentStorageService::class)->safeDownloadName($attachment);

        $this->assertSame('evil.pdf', $safe);
        $this->assertStringNotContainsString('\\', $safe);
        $this->assertStringNotContainsString('..', $safe);
        $this->assertStringNotContainsString('folder', $safe);
    }

    public function test_crlf_and_control_characters_are_stripped_from_the_filename(): void
    {
        $attachment = $this->makeAttachment(fileName: "evil.jpg\r\nX-Injected: 1\x00\x07");

        $safe = app(AttachmentStorageService::class)->safeDownloadName($attachment);

        $this->assertStringNotContainsString("\r", $safe);
        $this->assertStringNotContainsString("\n", $safe);
        $this->assertStringNotContainsString("\x00", $safe);
        $this->assertStringNotContainsString("\x07", $safe);
    }

    public function test_empty_filename_falls_back_to_a_deterministic_name(): void
    {
        $attachment = $this->makeAttachment(fileName: '', path: 'general-expenses/whatever.jpg');

        $safe = app(AttachmentStorageService::class)->safeDownloadName($attachment);

        $this->assertSame('attachment-'.$attachment->id.'.jpg', $safe);
    }

    public function test_dot_and_dot_dot_filenames_fall_back_to_a_deterministic_name(): void
    {
        $attachment = $this->makeAttachment(fileName: '..', path: 'general-expenses/whatever.png');

        $safe = app(AttachmentStorageService::class)->safeDownloadName($attachment);

        $this->assertSame('attachment-'.$attachment->id.'.png', $safe);
    }

    public function test_unicode_arabic_filename_is_preserved(): void
    {
        $attachment = $this->makeAttachment(fileName: 'إيصال_123.jpg');

        $safe = app(AttachmentStorageService::class)->safeDownloadName($attachment);

        $this->assertSame('إيصال_123.jpg', $safe);
    }

    public function test_normal_server_generated_filename_is_unchanged(): void
    {
        $attachment = $this->makeAttachment(fileName: 'gen_42_20260701_100.jpg');

        $safe = app(AttachmentStorageService::class)->safeDownloadName($attachment);

        $this->assertSame('gen_42_20260701_100.jpg', $safe);
    }

    // =========================================================
    // HTTP-level: the real headers use the sanitized name
    // =========================================================

    public function test_inline_response_uses_the_sanitized_filename(): void
    {
        $attachment = $this->putAndAttach("evil.jpg\r\nX-Injected: 1");

        $this->actingAs($this->userWithGeneralExpensesView());

        $response = $this->get("/attachments/{$attachment->id}/view");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
    }

    public function test_download_response_uses_the_sanitized_filename(): void
    {
        $attachment = $this->putAndAttach('../../etc/evil.jpg');

        $this->actingAs($this->userWithGeneralExpensesView());

        $response = $this->get("/attachments/{$attachment->id}/download");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('evil.jpg', $disposition);
        $this->assertStringNotContainsString('../', $disposition);
    }

    /**
     * The Windows-backslash counterpart to
     * test_download_response_uses_the_sanitized_filename: proves the real
     * HTTP response header never contains 'folder' or a backslash for a
     * '..\..\folder\evil.pdf'-shaped stored file_name, regardless of the
     * server's OS (see lastPathSegment()'s docblock).
     */
    public function test_download_response_strips_windows_directory_segments(): void
    {
        $attachment = $this->putAndAttach('..\\..\\folder\\evil.pdf');

        $this->actingAs($this->userWithGeneralExpensesView());

        $response = $this->get("/attachments/{$attachment->id}/download");

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('evil.pdf', $disposition);
        $this->assertStringNotContainsString('folder', $disposition);
        $this->assertStringNotContainsString('\\', $disposition);
    }

    public function test_unicode_filename_survives_a_real_download_response(): void
    {
        $attachment = $this->putAndAttach('إيصال_123.jpg');

        $this->actingAs($this->userWithGeneralExpensesView());

        $response = $this->get("/attachments/{$attachment->id}/download");

        $response->assertOk();
        $response->assertHeader('Content-Disposition');
    }

    // =========================================================
    // helpers
    // =========================================================

    private function userWithGeneralExpensesView(): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['name' => 'general_expenses.view', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);

        return $user;
    }

    private function makeAttachment(string $fileName, ?string $path = null): Attachment
    {
        $expense = GeneralExpense::create(['amount' => 100, 'date' => '2026-07-01']);

        return Attachment::create([
            'attachable_type' => GeneralExpense::class,
            'attachable_id' => $expense->id,
            'file_name' => $fileName,
            'file_path' => $path ?? 'general-expenses/source.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 16,
            'disk' => Attachment::DISK_ATTACHMENTS,
        ]);
    }

    private function putAndAttach(string $fileName): Attachment
    {
        $path = 'general-expenses/source-'.uniqid().'.jpg';

        Storage::disk(Attachment::DISK_ATTACHMENTS)->put($path, 'fake-file-bytes');

        return $this->makeAttachment($fileName, $path);
    }
}
