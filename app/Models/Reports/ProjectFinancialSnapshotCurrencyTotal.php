<?php

namespace App\Models\Reports;

use App\Models\Currency;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFinancialSnapshotCurrencyTotal extends Model
{
    protected $table = 'project_financial_snapshot_currency_totals';

    protected $fillable = [
        'project_id',
        'currency_id',
        'currency_code',
        'planned',
        'received',
        'remaining_to_receive',
        'budget_original',
        'budget_after_deductions',
        'budget_final',
        'execution_paid',
        'remaining_execution',
        'deductions_total',
        'execution_pct_of_planned',
        'execution_pct_of_final',
    ];

    protected function casts(): array
    {
        return [
            'planned'                  => 'decimal:2',
            'received'                 => 'decimal:2',
            'remaining_to_receive'     => 'decimal:2',
            'budget_original'          => 'decimal:2',
            'budget_after_deductions'  => 'decimal:2',
            'budget_final'             => 'decimal:2',
            'execution_paid'           => 'decimal:2',
            'remaining_execution'      => 'decimal:2',
            'deductions_total'         => 'decimal:2',
            'execution_pct_of_planned' => 'decimal:2',
            'execution_pct_of_final'   => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ProjectFinancialSnapshot::class, 'project_id', 'project_id');
    }
}
