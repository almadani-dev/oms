<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectCostBudget extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $fillable = [
        'project_cost_id',
        'administrative_percentage',
        'transfer_percentage',
        'exchange_percentage',
        'amount_after_percentages',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'administrative_percentage' => 'decimal:2',
            'transfer_percentage'       => 'decimal:2',
            'exchange_percentage'       => 'decimal:2',
            'amount_after_percentages'  => 'decimal:2',
        ];
    }

    public function projectCost(): BelongsTo
    {
        return $this->belongsTo(ProjectCost::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ProjectCostBudgetsPayment::class);
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
