<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectStatus extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $table = 'projects_status';

    protected $fillable = [
        'name',
        'color',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'project_status_id');
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
