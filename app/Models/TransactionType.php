<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransactionType extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $table = 'transactions_types';

    protected $fillable = [
        'transaction_super_type_id',
        'name',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function transactionSuperType(): BelongsTo
    {
        return $this->belongsTo(TransactionSuperType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'transaction_type_id');
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
