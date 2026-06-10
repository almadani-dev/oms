<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountType extends Model
{
    use SoftDeletes;

    protected $table = 'accounts_type';

    protected $fillable = [
        'name',
        'notes',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_type_id');
    }
}
