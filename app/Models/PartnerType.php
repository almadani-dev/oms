<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartnerType extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $table = 'partners_types';

    protected $fillable = [
        'name',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class, 'partner_type_id');
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
