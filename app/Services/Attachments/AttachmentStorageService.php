<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The only place in the app that turns an Attachment row into bytes on the
 * wire. Deliberately narrow: it does not upload, move, or migrate files
 * (that stays in each Resource's Create/Edit pages for now, and in the
 * Task 6B legacy-migration Artisan command later) - it only resolves an
 * approved disk, validates the stored path, checks existence, determines a
 * safe MIME type, and builds a streamed response with private/no-store
 * headers and a sanitized filename.
 *
 * A row's file_path and file_name are database values, not request input -
 * but they are still untrusted here: they were written by application code
 * that itself might change, and a corrupted/hand-edited row must never be
 * able to turn this service into an arbitrary-file reader. Every method
 * that touches the filesystem goes through isSafeRelativePath() first, and
 * the actual read always goes through Storage/Flysystem - never a manually
 * concatenated absolute path.
 */
class AttachmentStorageService
{
    /**
     * Resolve the disk for an attachment, but only if its stored 'disk'
     * value is one of Attachment::APPROVED_DISKS. A row with any other
     * value (corrupt data, future typo) never reaches Storage::disk() -
     * this is the single choke point that keeps a database value from
     * selecting an arbitrary Laravel filesystem disk.
     */
    public function resolveDisk(Attachment $attachment): ?FilesystemAdapter
    {
        if (! in_array($attachment->disk, Attachment::APPROVED_DISKS, true)) {
            return null;
        }

        return Storage::disk($attachment->disk);
    }

    /**
     * Whether a stored file_path is safe to hand to Storage/Flysystem at
     * all. Rejects anything that isn't a plain path relative to the disk
     * root: absolute Unix paths, absolute/UNC Windows paths, drive-letter
     * paths, null bytes, empty values, and any '..' path segment (checked
     * after splitting on BOTH '/' and '\' so mixed-separator traversal
     * attempts are caught the same way). Rejecting every '..' segment is
     * also what rules out the path ever normalizing outside the disk root -
     * there is no other way for a relative path to climb upward.
     *
     * This is a defense-in-depth pre-check, not a replacement for
     * Flysystem's own protection: exists()/toResponse() still call through
     * Storage as normal afterward, and still catch Flysystem's own
     * PathTraversalDetected on top of this.
     */
    public function isSafeRelativePath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        // Absolute Unix path.
        if (str_starts_with($path, '/')) {
            return false;
        }

        // Absolute/UNC Windows path (leading backslash) or a drive-letter
        // path such as "C:\..." / "C:/...".
        if (str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return false;
        }

        foreach (preg_split('#[\\\\/]+#', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the attachment's file is actually present on its resolved
     * disk. False (never an exception) for an unapproved disk, an unsafe
     * stored path, a disk-level existence-check failure, or a path
     * Flysystem refuses to resolve.
     */
    public function exists(Attachment $attachment): bool
    {
        if (! $this->isSafeRelativePath($attachment->file_path)) {
            return false;
        }

        $disk = $this->resolveDisk($attachment);

        if (! $disk) {
            return false;
        }

        try {
            return $disk->exists($attachment->file_path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Prefer a MIME type read from the actual file content/extension via
     * the disk's Flysystem adapter; fall back to the stored file_type only
     * when it looks like a genuine MIME token (contains a '/'); otherwise
     * application/octet-stream. Never trusts the stored value blindly, and
     * never asks the disk to resolve an unsafe path.
     */
    public function mimeType(Attachment $attachment): string
    {
        $disk = $this->resolveDisk($attachment);

        if ($disk && $this->isSafeRelativePath($attachment->file_path)) {
            try {
                $mime = $disk->mimeType($attachment->file_path);

                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            } catch (Throwable) {
                // Fall through to the stored value.
            }
        }

        $stored = $attachment->file_type;

        if (is_string($stored) && str_contains($stored, '/')) {
            return $stored;
        }

        return 'application/octet-stream';
    }

    /**
     * A Content-Disposition-safe filename derived from the attachment's own
     * stored file_name: lastPathSegment() strips any directory segments (so
     * an absolute or traversal-shaped file_name can never surface a path),
     * then every C0/C1 control character - including CR and LF, which is
     * what actually matters for header-injection safety - is stripped.
     * Falls back to a deterministic 'attachment-{id}.{ext}' name if that
     * leaves nothing usable (empty, or only '.'/'..').
     */
    public function safeDownloadName(Attachment $attachment): string
    {
        $name = $attachment->file_name;
        $name = is_string($name) ? $this->lastPathSegment($name) : '';
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return $this->fallbackDownloadName($attachment);
        }

        return $name;
    }

    /**
     * basename() is platform-dependent for backslash separators: on
     * Windows it treats '\' as a directory separator, but on Linux/macOS it
     * does not, so a value like '..\\..\\folder\\evil.pdf' would pass
     * through PHP's own basename() completely unchanged on a Linux server -
     * silently leaking directory segments (and their backslashes) straight
     * into Content-Disposition. This splits on BOTH '/' and '\' explicitly
     * (the same approach isSafeRelativePath() uses) so the result is
     * identical regardless of which OS this code happens to run on.
     */
    private function lastPathSegment(string $path): string
    {
        $segments = array_values(array_filter(
            preg_split('#[\\\\/]+#', $path),
            fn (string $segment): bool => $segment !== '',
        ));

        return $segments === [] ? '' : end($segments);
    }

    private function fallbackDownloadName(Attachment $attachment): string
    {
        $extension = $this->safeExtension($attachment);

        return 'attachment-'.$attachment->id.($extension !== '' ? '.'.$extension : '');
    }

    /**
     * Tries file_name first, then file_path, returning the first non-empty
     * extension found. A source of '..' or '.' (rejected by
     * safeDownloadName() as a usable name, but still "truthy") yields no
     * extension on its own, so file_path must still get its turn rather
     * than being skipped by a simple `?:`. Extension characters are
     * restricted to [A-Za-z0-9] regardless of source, so even a
     * control-character-laden or path-shaped value can only ever
     * contribute a short alphanumeric suffix here.
     */
    private function safeExtension(Attachment $attachment): string
    {
        foreach ([$attachment->file_name, $attachment->file_path] as $source) {
            if (! is_string($source) || $source === '') {
                continue;
            }

            $extension = pathinfo($this->lastPathSegment($source), PATHINFO_EXTENSION);
            $extension = preg_replace('/[^A-Za-z0-9]/', '', (string) $extension) ?? '';

            if ($extension !== '') {
                return strtolower(substr($extension, 0, 10));
            }
        }

        return '';
    }

    /**
     * Build the streamed response for an already-authorized attachment.
     * Callers (the controller) are responsible for authorization before
     * calling this - it independently re-verifies the disk and the stored
     * path/file are safe and present via exists(), and aborts 404 (never a
     * 500) if not.
     *
     * $disposition must be 'inline' (preview) or 'attachment' (download).
     * The download name always comes from safeDownloadName(), never the
     * raw stored value and never the request.
     */
    public function toResponse(Attachment $attachment, string $disposition): StreamedResponse
    {
        abort_unless($this->exists($attachment), 404);

        $disk = $this->resolveDisk($attachment);

        $headers = [
            'Content-Type' => $this->mimeType($attachment),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        try {
            return $disk->response($attachment->file_path, $this->safeDownloadName($attachment), $headers, $disposition);
        } catch (PathTraversalDetected) {
            abort(404);
        }
    }
}
