<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankType extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $table = 'bank_types';

    protected $fillable = [
        'name',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
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
