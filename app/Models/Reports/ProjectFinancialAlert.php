<?php

namespace App\Models\Reports;

use App\Models\Currency;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFinancialAlert extends Model
{
    protected $table = 'project_financial_alerts';

    // Severity values.
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_NOTE     = 'note';

    protected $fillable = [
        'project_id',
        'severity',
        'title',
        'message',
        'currency_id',
        'currency_code',
        'amount',
        'reference_type',
        'reference_id',
        'meta',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:2',
            'meta'          => 'array',
            'calculated_at' => 'datetime',
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
