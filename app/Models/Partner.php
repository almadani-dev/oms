<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Partner extends Model
{
    use SoftDeletes, HasUserTracking;

    protected $fillable = [
        'name',
        'partner_type_id',
        'is_donor',
        'email',
        'mobile_number',
        'address',
        'city',
        'country',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_donor' => 'boolean',
        ];
    }

    public function partnerType(): BelongsTo
    {
        return $this->belongsTo(PartnerType::class, 'partner_type_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'donor_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
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
