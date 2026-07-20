<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    /**
     * Matches the migration's column default — without this, a freshly
     * `create()`d instance held in memory (e.g. the model object
     * `actingAs()` reuses across an entire test) reads `is_active` as null
     * until a `fresh()`/re-fetch, since Eloquent doesn't reflect a
     * database-level column default back onto the in-memory attributes
     * after an insert.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Panel entry only — deliberately not a permission check. Every
     * resource/page/action's actual authorization is decided by
     * Gate::before, Policies, and Resource/Page canX() methods, not here.
     * Runs on every panel request, so a deactivated/soft-deleted user is
     * denied on their very next request even if already authenticated.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->trashed() && $this->is_active;
    }
}
