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
        'administrative_percentage',
        'implementation_amount',
        'received_amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'                    => 'decimal:2',
            'administrative_percentage' => 'decimal:2',
            'implementation_amount'     => 'decimal:2',
            'received_amount'           => 'decimal:2',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $model) {
            $amount = floatval($model->amount);
            $percentage = floatval($model->administrative_percentage);
            $model->implementation_amount = round($amount - ($amount * $percentage / 100), 2);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class);
    }

    public function transactionLines(): HasMany
    {
        return $this->hasMany(TransactionLine::class, 'project_cost_id');
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
