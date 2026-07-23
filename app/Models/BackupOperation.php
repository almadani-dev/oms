<?php

namespace App\Models;

use App\Enums\BackupScope;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Metadata-only record of one backup **or restore** operation (OMS Task 7C
 * reuses this table for a restore's own audit row via `type = Restore` —
 * see BackupType::Restore). Never holds the encrypted archive's bytes, the
 * encryption key, database credentials, a raw command string, or an
 * absolute filesystem path — `stored_path` is always relative to `disk`.
 * See the Task 7A audit's "Metadata schema" section for the full list of
 * what this table must never store.
 *
 * `restore_metadata` (added in Task 7C.1) is the single bounded JSON audit
 * container for everything restore-specific that doesn't fit the
 * backup-shaped columns above: requester identity snapshot (used when
 * `created_by` can't be satisfied because the referenced user row doesn't
 * exist in a freshly-restored database), source/safety backup UUID
 * snapshots, confirmation timestamp, phase history, and sanitized
 * reconciliation/result context. It must never contain a confirmation
 * phrase, password, key, raw command line, or unbounded/unsanitized
 * exception text. No code in Task 7C.1 writes to it yet — the future
 * progress-writer (Task 7C.2+) is responsible for keeping its
 * `phase_history` bounded to at most
 * self::RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES entries (oldest dropped
 * first), so this column can never grow without bound across a long-running
 * restore.
 *
 * `launch_nonce` (added in Task 7C.1) is a concurrency/idempotency key, not
 * audit data — the future signed launch endpoint (Task 7C.4) matches on it
 * in an atomic conditional UPDATE so a replayed signed launch URL can never
 * claim/spawn a second restore process for the same request.
 */
class BackupOperation extends Model
{
    use SoftDeletes;

    /**
     * Upper bound the future restore progress-writer (Task 7C.2+) must
     * enforce on `restore_metadata['phase_history']` — documented and
     * tested here ahead of that implementation so the intended bounded
     * shape is locked in before any writer exists, per the Task 7C.1 scope
     * (no progress-writing logic itself belongs in this phase or in this
     * model).
     */
    public const RESTORE_METADATA_MAX_PHASE_HISTORY_ENTRIES = 50;

    protected $fillable = [
        'uuid',
        'type',
        'scope',
        'status',
        'deduplication_key',
        'disk',
        'stored_path',
        'encrypted_filename',
        'size_bytes',
        'checksum_sha256',
        'manifest_version',
        'encryption_key_id',
        'file_count',
        'original_size_bytes',
        'started_at',
        'completed_at',
        'verified_at',
        'failed_at',
        'created_by',
        'operation_reason',
        'error_summary',
        'is_protected',
        'source_backup_id',
        'pre_restore_safety_backup_id',
        'restore_metadata',
        'launch_nonce',
    ];

    protected $casts = [
        'type' => BackupType::class,
        'scope' => BackupScope::class,
        'status' => BackupStatus::class,
        'size_bytes' => 'integer',
        'manifest_version' => 'integer',
        'file_count' => 'integer',
        'original_size_bytes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
        'failed_at' => 'datetime',
        'is_protected' => 'boolean',
        'restore_metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $operation): void {
            if (empty($operation->uuid)) {
                $operation->uuid = (string) Str::uuid();
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceBackup(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_backup_id');
    }

    public function preRestoreSafetyBackup(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pre_restore_safety_backup_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === BackupStatus::Completed;
    }

    public function isVerified(): bool
    {
        return $this->isCompleted() && $this->verified_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status instanceof BackupStatus && $this->status->isActive();
    }

    /**
     * True for this row's own type, not for anything about what it points
     * at — used by deletion-eligibility/lock-guard code (Task 7C) that
     * needs to find restore-type rows without duplicating a `type ===`
     * comparison at every call site.
     */
    public function isRestoreOperation(): bool
    {
        return $this->type === BackupType::Restore;
    }
}
