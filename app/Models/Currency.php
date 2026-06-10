<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Currency extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $fillable = [
        'name',
        'code',
        'symbol',
        'is_base',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
        ];
    }

    public function exchangeRateHistory(): HasMany
    {
        return $this->hasMany(ExchangeRateHistory::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function transactionLines(): HasMany
    {
        return $this->hasMany(TransactionLine::class);
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
