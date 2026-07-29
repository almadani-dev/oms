<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `created_by`/`updated_by` exist on `accounts_type` since Task 8.2's
 * schema-drift reconciliation (2026_07_28_110001), which deliberately
 * reconciled the COLUMNS only and left activating the behavior to the Audit
 * Log work. OMS Task 9B.3 activates it: HasUserTracking now populates both on
 * every save from this point forward. The rows already carrying real
 * historical values keep them; nothing is backfilled.
 */
class AccountType extends Model
{
    use HasUserTracking, SoftDeletes;

    protected $table = 'accounts_type';

    protected $fillable = [
        'name',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_type_id');
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
