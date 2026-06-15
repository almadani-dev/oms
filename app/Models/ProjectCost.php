<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectCost extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $table = 'projects_costs';

    protected $fillable = [
        'project_id',
        'account_type_id',
        'amount',
        'currency_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function transactionLines(): HasMany
    {
        return $this->hasMany(TransactionLine::class, 'project_cost_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(ProjectCostBudget::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ProjectCostReceipt::class);
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
