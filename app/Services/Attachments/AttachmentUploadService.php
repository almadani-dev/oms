<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The only place in the app that turns a Filament private-disk temporary
 * upload into a stored Attachment row + final file, for the five OMS Task
 * 6B financial Resources. $directory and $prefix are always literal strings
 * hardcoded by the calling Resource's own code - never derived from
 * request/form data - and are still re-checked here against an explicit
 * allowlist as defense in depth. The destination disk is never a caller
 * choice at all: it is always Attachment::DISK_ATTACHMENTS.
 *
 * Deliberately narrow, mirroring AttachmentStorageService's own scope: this
 * class only stores a brand-new attachment. It never soft-deletes a prior
 * attachment, never touches authorization, and never builds a download
 * response - those stay in the calling Resource page and in
 * AttachmentStorageService respectively.
 */
class AttachmentUploadService
{
    private const ALLOWED_DIRECTORIES = [
        'receipts',
        'payments',
        'execution-payments',
        'general-expenses',
        'general-exchanges',
    ];

    private const ALLOWED_PREFIXES = [
        'receive',
        'pay',
        'gen',
        'ext',
    ];

    public function __construct(private readonly AttachmentStorageService $storage)
    {
    }

    /**
     * Store $tempPath (a path already written onto the private 'attachments'
     * disk by Filament's FileUpload, under one of the allowlisted
     * directories) as $parent's new active Attachment, named
     * "{prefix}_{attachment_id}_{Ymd}_{amount}.{ext}".
     *
     * The Attachment row is created first (with a temporary file_name/
     * file_path) purely so its id is available for the final deterministic
     * filename - never invented via MAX+1. It is then updated in place once
     * the file has been moved to its final path.
     */
    public function store(
        Model $parent,
        string $tempPath,
        string $directory,
        string $prefix,
        DateTimeInterface|string $date,
        float $amount,
    ): Attachment {
        if (! in_array($directory, self::ALLOWED_DIRECTORIES, true)) {
            throw new InvalidArgumentException("Unapproved attachment directory: {$directory}");
        }

        if (! in_array($prefix, self::ALLOWED_PREFIXES, true)) {
            throw new InvalidArgumentException("Unapproved attachment prefix: {$prefix}");
        }

        if (! $this->storage->isSafeRelativePath($tempPath)) {
            throw new InvalidArgumentException('Unsafe temporary attachment path.');
        }

        $disk = Storage::disk(Attachment::DISK_ATTACHMENTS);

        if (! $disk->exists($tempPath)) {
            throw new RuntimeException("Temporary attachment file not found: {$tempPath}");
        }

        $extension = $this->extractExtension($tempPath);
        $mimeType  = $this->safeMimeType($disk, $tempPath);
        $fileSize  = $disk->size($tempPath);
        $fileSize  = is_int($fileSize) ? $fileSize : 0;

        $attachment = Attachment::create([
            'attachable_type' => $parent::class,
            'attachable_id'   => $parent->getKey(),
            'file_name'       => $this->lastPathSegment($tempPath),
            'file_path'       => $tempPath,
            'file_type'       => $mimeType,
            'file_size'       => $fileSize,
            'disk'            => Attachment::DISK_ATTACHMENTS,
            'created_by'      => auth()->id(),
            'updated_by'      => auth()->id(),
        ]);

        $fileName = $prefix.'_'.$attachment->id
            .'_'.Carbon::parse($date)->format('Ymd')
            .'_'.(int) $amount
            .($extension !== '' ? '.'.$extension : '');

        $finalPath = $directory.'/'.$fileName;

        // The 'attachments' disk is configured with 'throw' => false, so a
        // Flysystem-level move failure surfaces as a false return, not an
        // exception - both are handled the same way here.
        $moved = false;

        try {
            $moved = $disk->move($tempPath, $finalPath);
        } catch (Throwable) {
            $moved = false;
        }

        if (! $moved) {
            $attachment->forceDelete();

            throw new RuntimeException("Failed to move attachment file to final path: {$finalPath}");
        }

        try {
            $attachment->update([
                'file_name' => $fileName,
                'file_path' => $finalPath,
            ]);
        } catch (Throwable $e) {
            // The file already landed at its final path but the row could
            // not be finalized - clean up only this newly-created file, and
            // only this newly-created (still-temporary) row. Never touches
            // any prior attachment.
            $disk->delete($finalPath);
            $attachment->forceDelete();

            throw $e;
        }

        return $attachment->refresh();
    }

    /**
     * Splits on both '/' and '\' (mirrors AttachmentStorageService's own
     * lastPathSegment()) so the result does not depend on which OS this
     * happens to run on.
     */
    private function lastPathSegment(string $path): string
    {
        $segments = array_values(array_filter(
            preg_split('#[\\\\/]+#', $path),
            fn (string $segment): bool => $segment !== '',
        ));

        return $segments === [] ? '' : end($segments);
    }

    private function extractExtension(string $path): string
    {
        $extension = pathinfo($this->lastPathSegment($path), PATHINFO_EXTENSION);
        $extension = preg_replace('/[^A-Za-z0-9]/', '', (string) $extension) ?? '';

        return strtolower(substr($extension, 0, 10));
    }

    private function safeMimeType(Filesystem $disk, string $path): string
    {
        try {
            $mime = $disk->mimeType($path);

            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        } catch (Throwable) {
            // Fall through to the generic default below.
        }

        return 'application/octet-stream';
    }
}
