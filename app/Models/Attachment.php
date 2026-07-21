<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use SoftDeletes, HasUserTracking;

    /**
     * The only disk names AttachmentStorageService will ever resolve
     * Storage::disk() against for a given row. A 'disk' value outside this
     * list (corrupt data, a future typo, manual DB editing) must never
     * reach Storage::disk() - see AttachmentStorageService::resolveDisk().
     */
    public const DISK_PUBLIC = 'public';

    public const DISK_ATTACHMENTS = 'attachments';

    public const APPROVED_DISKS = [
        self::DISK_PUBLIC,
        self::DISK_ATTACHMENTS,
    ];

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'disk',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
