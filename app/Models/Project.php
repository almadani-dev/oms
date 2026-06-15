<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $fillable = [
        'name',
        'code',
        'approval_date',
        'implementation_date',
        'start_date',
        'end_date',
        'donor_project_name',
        'donor_id',
        'project_super_id',
        'project_status_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'approval_date'       => 'date',
            'implementation_date' => 'date',
            'start_date'          => 'date',
            'end_date'            => 'date',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $super = ProjectSuper::find($model->project_super_id);
            $prefix = strtoupper($super?->code_prefix ?? 'PRJ');
            $date = $model->approval_date
                ? Carbon::parse($model->approval_date)->format('Ymd')
                : Carbon::now()->format('Ymd');
            $count = Project::withTrashed()
                ->where('project_super_id', $model->project_super_id)
                ->count() + 1;
            $model->code = $prefix . '_' . $date . '_' . str_pad($count, 3, '0', STR_PAD_LEFT);
        });
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'donor_id');
    }

    public function projectSuper(): BelongsTo
    {
        return $this->belongsTo(ProjectSuper::class, 'project_super_id');
    }

    public function projectStatus(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'project_status_id');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(ProjectCost::class);
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
