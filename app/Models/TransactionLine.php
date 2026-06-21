<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransactionLine extends Model
{
    use HasUserTracking, SoftDeletes;

    protected $fillable = [
        'transaction_id',
        'account_id',
        'project_cost_id',
        'currency_id',
        'amount_currency',
        'fx_rate',
        'debit_base',
        'credit_base',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_currency' => 'decimal:2',
            'fx_rate'         => 'decimal:6',
            'debit_base'      => 'decimal:2',
            'credit_base'     => 'decimal:2',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function projectCost(): BelongsTo
    {
        return $this->belongsTo(ProjectCost::class, 'project_cost_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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
