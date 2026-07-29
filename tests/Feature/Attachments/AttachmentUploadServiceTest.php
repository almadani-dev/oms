<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\GeneralExpense;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Attachments\AttachmentUploadService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * OMS Task 6B: direct storage/failure-invariant tests for the new
 * AttachmentUploadService, the single place that turns a Filament
 * private-disk temporary upload into a stored Attachment row + final file
 * for the five financial Resources.
 *
 * Uses the same schema-only SQLite migration bootstrap as the Task 6A
 * Attachment tests (see AttachmentAccessTest), plus Storage::fake() for both
 * disks - no real file or real database row is ever touched. GeneralExpense
 * is used as a lightweight, already-existing real parent model; the service
 * itself is parent-model-agnostic.
 */
class AttachmentUploadServiceTest extends TestCase
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

        Storage::fake('public');
        Storage::fake('attachments');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Resolved from the container rather than hand-constructed: since OMS
     * Task 9B.5 the service also depends on AttachmentAuditRecorder, and the
     * container is the only place that wiring should be spelled out.
     */
    private function service(): AttachmentUploadService
    {
        return app(AttachmentUploadService::class);
    }

    /**
     * A successful store() now records a REQUIRED attachment audit event,
     * which (like every REQUIRED audit write in this codebase) must belong to
     * the caller's own transaction — exactly as all ten real call sites in
     * the five financial Create/Edit pages already do. These direct-service
     * tests therefore open one too. Failure-path tests deliberately do not:
     * they never reach the audit call at all.
     */
    private function storeInTransaction(callable $callback): Attachment
    {
        return DB::transaction($callback);
    }

    private function parent(): GeneralExpense
    {
        return GeneralExpense::create(['amount' => 100, 'date' => '2026-07-01']);
    }

    // =========================================================
    // Directory/prefix/filename scheme - parameterized across all
    // five approved mappings.
    // =========================================================

    public static function approvedMappings(): array
    {
        return [
            'receipts/receive' => ['receipts', 'receive'],
            'payments/pay' => ['payments', 'pay'],
            'execution-payments/pay' => ['execution-payments', 'pay'],
            'general-expenses/gen' => ['general-expenses', 'gen'],
            'general-exchanges/ext' => ['general-exchanges', 'ext'],
        ];
    }

    #[DataProvider('approvedMappings')]
    public function test_store_creates_one_attachment_on_the_private_disk_with_the_deterministic_filename(string $directory, string $prefix): void
    {
        $parent = $this->parent();
        Storage::disk('attachments')->put('livewire-tmp/incoming.jpg', 'fake-bytes');

        $attachment = $this->storeInTransaction(fn () => $this->service()->store(
            parent: $parent,
            tempPath: 'livewire-tmp/incoming.jpg',
            directory: $directory,
            prefix: $prefix,
            date: '2026-07-15',
            amount: 123.75,
        ));

        $this->assertSame(1, Attachment::count());
        $this->assertSame(Attachment::DISK_ATTACHMENTS, $attachment->disk);
        $this->assertSame(GeneralExpense::class, $attachment->attachable_type);
        $this->assertSame($parent->id, $attachment->attachable_id);

        $expectedName = "{$prefix}_{$attachment->id}_20260715_123.jpg";
        $this->assertSame($directory.'/'.$expectedName, $attachment->file_path);
        $this->assertSame($expectedName, $attachment->file_name);

        Storage::disk('attachments')->assertExists($attachment->file_path);
        Storage::disk('attachments')->assertMissing('livewire-tmp/incoming.jpg');
        Storage::disk('public')->assertMissing($attachment->file_path);

        $this->assertNotEmpty($attachment->file_type);
        $this->assertGreaterThan(0, $attachment->file_size);
    }

    public function test_amount_is_truncated_to_an_integer_in_the_filename(): void
    {
        $parent = $this->parent();
        Storage::disk('attachments')->put('livewire-tmp/a.png', 'x');

        $attachment = $this->storeInTransaction(fn () => $this->service()->store($parent, 'livewire-tmp/a.png', 'general-expenses', 'gen', '2026-01-05', 99.999));

        $this->assertSame("gen_{$attachment->id}_20260105_99.png", $attachment->file_name);
    }

    public function test_extension_is_preserved_case_insensitively(): void
    {
        $parent = $this->parent();
        Storage::disk('attachments')->put('livewire-tmp/a.PDF', 'x');

        $attachment = $this->storeInTransaction(fn () => $this->service()->store($parent, 'livewire-tmp/a.PDF', 'general-expenses', 'gen', '2026-01-05', 10));

        $this->assertStringEndsWith('.pdf', $attachment->file_name);
    }

    // =========================================================
    // Allowlist enforcement - directory/prefix are never taken
    // from request data, but re-checked defensively anyway.
    // =========================================================

    public function test_unapproved_directory_is_rejected(): void
    {
        $parent = $this->parent();
        Storage::disk('attachments')->put('livewire-tmp/a.jpg', 'x');

        $this->expectException(InvalidArgumentException::class);

        $this->service()->store($parent, 'livewire-tmp/a.jpg', 'evil-directory', 'gen', '2026-01-05', 10);
    }

    public function test_unapproved_prefix_is_rejected(): void
    {
        $parent = $this->parent();
        Storage::disk('attachments')->put('livewire-tmp/a.jpg', 'x');

        $this->expectException(InvalidArgumentException::class);

        $this->service()->store($parent, 'livewire-tmp/a.jpg', 'general-expenses', 'evil', '2026-01-05', 10);
    }

    // =========================================================
    // Malicious/unsafe temporary paths are rejected outright.
    // =========================================================

    public static function unsafePaths(): array
    {
        return [
            'absolute unix path' => ['/etc/passwd'],
            'traversal segment' => ['general-expenses/../../etc/passwd'],
            'backslash traversal' => ['general-expenses\\..\\..\\etc\\passwd'],
            'windows drive path' => ['C:\\Windows\\System32\\config'],
            'null byte' => ["general-expenses/evil\0.jpg"],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_temporary_paths_are_rejected(string $path): void
    {
        $parent = $this->parent();

        try {
            $this->service()->store($parent, $path, 'general-expenses', 'gen', '2026-01-05', 10);
            $this->fail('Expected an InvalidArgumentException for an unsafe temporary path.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, Attachment::count());
    }

    public function test_missing_temporary_file_is_rejected(): void
    {
        $parent = $this->parent();

        try {
            $this->service()->store($parent, 'livewire-tmp/never-uploaded.jpg', 'general-expenses', 'gen', '2026-01-05', 10);
            $this->fail('Expected a RuntimeException for a missing temporary file.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Attachment::count());
    }

    // =========================================================
    // Failure/rollback invariants
    // =========================================================

    /**
     * The 'attachments' disk is configured with 'throw' => false, so a real
     * Flysystem-level move failure surfaces as move() returning false, not
     * an exception - simulated here with a Mockery spy standing in for the
     * disk so the failure branch is exercised deterministically. No
     * Attachment row may be left behind pointing at a file that was never
     * actually written to its final path.
     */
    public function test_no_dangling_attachment_row_remains_when_the_move_fails(): void
    {
        $parent = $this->parent();
        Storage::disk('attachments')->put('livewire-tmp/a.jpg', 'fake-bytes');

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with('livewire-tmp/a.jpg')->andReturn(true);
        $disk->shouldReceive('mimeType')->andReturn('image/jpeg');
        $disk->shouldReceive('size')->andReturn(10);
        $disk->shouldReceive('move')->andReturn(false);

        Storage::shouldReceive('disk')->with(Attachment::DISK_ATTACHMENTS)->andReturn($disk);

        try {
            $this->service()->store($parent, 'livewire-tmp/a.jpg', 'general-expenses', 'gen', '2026-01-05', 10);
            $this->fail('Expected a RuntimeException for a failed move.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Attachment::count());
    }

    public function test_old_attachment_is_never_touched_by_a_failed_store_call(): void
    {
        $parent = $this->parent();

        $old = Attachment::create([
            'attachable_type' => GeneralExpense::class,
            'attachable_id' => $parent->id,
            'file_name' => 'gen_1_20260101_50.jpg',
            'file_path' => 'general-expenses/gen_1_20260101_50.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 10,
            'disk' => Attachment::DISK_ATTACHMENTS,
        ]);
        Storage::disk('attachments')->put($old->file_path, 'old-bytes');

        try {
            $this->service()->store($parent, 'livewire-tmp/missing.jpg', 'general-expenses', 'gen', '2026-01-05', 10);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($old->fresh()->trashed());
        Storage::disk('attachments')->assertExists($old->file_path);
        $this->assertSame(1, Attachment::count());
    }
}
