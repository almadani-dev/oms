<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRateHistory extends Model
{
    use HasUserTracking;

    protected $fillable = [
        'currency_id',
        'rate',
        'date',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'date' => 'date',
        ];
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
