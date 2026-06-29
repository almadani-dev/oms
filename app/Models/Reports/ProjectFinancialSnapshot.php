<?php

namespace App\Models\Reports;

use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectSuper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectFinancialSnapshot extends Model
{
    protected $table = 'project_financial_snapshots';

    protected $fillable = [
        'project_id',
        'project_code',
        'project_name',
        'project_super_id',
        'project_super_name',
        'donor_id',
        'donor_name',
        'project_status_id',
        'project_status_name',
        'approval_date',
        'implementation_date',
        'start_date',
        'end_date',
        'planned_by_currency',
        'received_by_currency',
        'remaining_to_receive_by_currency',
        'budget_original_by_currency',
        'budget_after_deductions_by_currency',
        'budget_final_by_currency',
        'execution_paid_by_currency',
        'remaining_execution_by_currency',
        'deductions_by_currency',
        'execution_pct_of_final_by_currency',
        'alerts_count',
        'critical_alerts_count',
        'warning_alerts_count',
        'notes_count',
        'most_severe_alert_title',
        'has_critical_alerts',
        'has_warning_alerts',
        'is_dirty',
        'calculated_at',
        'data_hash',
    ];

    protected function casts(): array
    {
        return [
            'approval_date'                        => 'date',
            'implementation_date'                  => 'date',
            'start_date'                           => 'date',
            'end_date'                             => 'date',
            'planned_by_currency'                  => 'array',
            'received_by_currency'                 => 'array',
            'remaining_to_receive_by_currency'     => 'array',
            'budget_original_by_currency'          => 'array',
            'budget_after_deductions_by_currency'  => 'array',
            'budget_final_by_currency'             => 'array',
            'execution_paid_by_currency'           => 'array',
            'remaining_execution_by_currency'      => 'array',
            'deductions_by_currency'               => 'array',
            'execution_pct_of_final_by_currency'   => 'array',
            'alerts_count'                         => 'integer',
            'critical_alerts_count'                => 'integer',
            'warning_alerts_count'                 => 'integer',
            'notes_count'                          => 'integer',
            'has_critical_alerts'                  => 'boolean',
            'has_warning_alerts'                   => 'boolean',
            'is_dirty'                             => 'boolean',
            'calculated_at'                        => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectSuper(): BelongsTo
    {
        return $this->belongsTo(ProjectSuper::class, 'project_super_id');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'donor_id');
    }

    public function projectStatus(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'project_status_id');
    }

    public function currencyTotals(): HasMany
    {
        return $this->hasMany(ProjectFinancialSnapshotCurrencyTotal::class, 'project_id', 'project_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(ProjectFinancialAlert::class, 'project_id', 'project_id');
    }
}
