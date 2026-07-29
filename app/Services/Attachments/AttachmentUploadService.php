<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Services\Audit\Attachments\AttachmentAuditRecorder;
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
 *
 * OMS Task 9B.5 - this is also the SINGLE audit choke point for attachment
 * uploads and replacements. All ten real upload call sites (the five
 * financial Create pages and the five Edit pages) already funnel through
 * store(), and there is deliberately no Attachment model observer and no
 * Attachment entry in the general-CRUD AuditSubjectRegistry, so an
 * `attachment.uploaded`/`attachment.replaced` event has exactly one possible
 * origin and cannot be emitted twice for one file action. Whether the write
 * is an upload or a replacement is not guessed here: the caller states it by
 * passing the outgoing attachment as $replacing.
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

    public function __construct(
        private readonly AttachmentStorageService $storage,
        private readonly AttachmentAuditRecorder $audit,
    ) {
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
     *
     * $replacing is the caller's currently-active Attachment when this store
     * is a REPLACEMENT (the five Edit pages pass it; the five Create pages do
     * not). Its metadata is snapshotted here, before the new row exists and
     * while the outgoing row is still the parent's active one, so the single
     * `attachment.replaced` event can carry both sides. Passing it does NOT
     * delete anything - the caller still owns that decision and its ordering,
     * exactly as before.
     */
    public function store(
        Model $parent,
        string $tempPath,
        string $directory,
        string $prefix,
        DateTimeInterface|string $date,
        float $amount,
        ?Attachment $replacing = null,
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

        // Snapshotted here, before anything is created or moved: this is the
        // last moment the outgoing attachment is unambiguously the parent's
        // active one, and it is the only remaining description of a file the
        // application will stop serving.
        $previous = $replacing !== null
            ? $this->audit->metadata()->of($replacing, 'replaced')
            : null;

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

        $attachment->refresh();

        // Only now are file_name/file_path/file_type/file_size authoritative,
        // so this is the earliest point an accurate event can be written -
        // and, being REQUIRED and inside the caller's own transaction, the
        // Attachment row cannot commit without it.
        $previous === null
            ? $this->audit->uploaded($attachment)
            : $this->audit->replaced($previous, $attachment);

        return $attachment;
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
