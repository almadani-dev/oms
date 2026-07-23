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
 * Metadata-only record of one backup (or, later, restore) operation. Never
 * holds the encrypted archive's bytes, the encryption key, database
 * credentials, a raw command string, or an absolute filesystem path —
 * `stored_path` is always relative to `disk`. See the Task 7A audit's
 * "Metadata schema" section for the full list of what this table must never
 * store.
 */
class BackupOperation extends Model
{
    use SoftDeletes;

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
}
