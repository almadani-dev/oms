<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\GeneralExpense;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * OMS Task 6A correction #2: a stored attachments.file_path is a database
 * value, not request input - but it must still never be trusted blindly.
 * Proves AttachmentStorageService::isSafeRelativePath() rejects every
 * traversal/absolute-path shape below, and that a malicious stored value
 * reaching the real HTTP route always ends in 404 - never a 500, and never
 * a response that actually read something outside the resolved disk.
 */
class AttachmentPathSafetyTest extends TestCase
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousPathProvider(): array
    {
        return [
            'unix absolute path' => ['/etc/passwd'],
            'unix absolute dotenv-shaped path' => ['../../.env'],
            'windows-style dot-dot traversal' => ['..\\..\\windows\\system.ini'],
            'windows absolute drive letter, backslash' => ['C:\\Windows\\system.ini'],
            'windows absolute drive letter, forward slash' => ['C:/Windows/system.ini'],
            'leading backslash (UNC-shaped)' => ['\\Windows\\system.ini'],
            'empty path' => [''],
            'mixed-separator traversal out of the disk root' => ['general-expenses/../../../etc/passwd'],
            'traversal reaching for a real repo file' => ['../../../../composer.json'],
        ];
    }

    #[DataProvider('maliciousPathProvider')]
    public function test_service_rejects_unsafe_stored_paths(string $maliciousPath): void
    {
        $service = app(AttachmentStorageService::class);

        $this->assertFalse($service->isSafeRelativePath($maliciousPath));
    }

    /**
     * A null byte can't reliably round-trip through every DB driver, so
     * this is asserted directly against the validator rather than via a
     * persisted row - the validator is what actually has to reject it.
     */
    public function test_service_rejects_a_null_byte_containing_path(): void
    {
        $service = app(AttachmentStorageService::class);

        $this->assertFalse($service->isSafeRelativePath("general-expenses/evil.jpg\0.php"));
    }

    public function test_service_accepts_genuine_relative_paths(): void
    {
        $service = app(AttachmentStorageService::class);

        $this->assertTrue($service->isSafeRelativePath('general-expenses/gen_1_20260701_100.jpg'));
        $this->assertTrue($service->isSafeRelativePath('a/b/c.png'));
    }

    #[DataProvider('maliciousPathProvider')]
    public function test_malicious_stored_path_returns_404_never_500(string $maliciousPath): void
    {
        $expense = GeneralExpense::create(['amount' => 100, 'date' => '2026-07-01']);

        $attachment = Attachment::create([
            'attachable_type' => GeneralExpense::class,
            'attachable_id' => $expense->id,
            'file_name' => 'gen_1_20260701_100.jpg',
            'file_path' => $maliciousPath,
            'file_type' => 'image/jpeg',
            'file_size' => 16,
            'disk' => Attachment::DISK_ATTACHMENTS,
        ]);

        $this->actingAs($this->superAdmin());

        $response = $this->get("/attachments/{$attachment->id}/view");

        $response->assertNotFound();

        // Never any file content back, and specifically never anything that
        // would only appear if a real file outside the disk had been read.
        $this->assertStringNotContainsString('"name"', $response->getContent() ?: '');
        $this->assertStringNotContainsString('APP_KEY', $response->getContent() ?: '');
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => PermissionRegistry::SUPER_ADMIN, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole(PermissionRegistry::SUPER_ADMIN);

        // Kept for parity with the other attachment test files even though
        // Super Admin doesn't need it - harmless if the module permission
        // already exists.
        Permission::firstOrCreate(['name' => 'general_expenses.view', 'guard_name' => 'web']);

        return $user;
    }
}
