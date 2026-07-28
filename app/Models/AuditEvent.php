<?php

namespace App\Models;

use App\Enums\AuditActorType;
use App\Enums\AuditStatus;
use App\Services\Audit\Exceptions\AuditImmutableRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One append-only row of OMS's audit trail (OMS Task 9B.1 foundation — not
 * yet written by any existing model/controller/command; App\Services\Audit\
 * AuditLogger is the only intended write path, added and wired to real
 * application operations starting in Task 9B.2+).
 *
 * Immutability is enforced at the application level, not via a DB trigger
 * (see the OMS Task 9A design audit's §F reasoning): every mutation path
 * Eloquent exposes on an existing row — save() after a fill/attribute
 * change, delete(), forceDelete(), replicate() — throws
 * AuditImmutableRecordException. A controlled future maintenance task may
 * still operate on this table via direct DB access; ordinary application
 * code must not.
 *
 * `subject_type` is a short, hand-maintained alias (e.g. "account"), never
 * a raw PHP FQCN — see the migration's own docblock. `actor_roles` is a
 * bounded snapshot of role NAMES only (never a permission dump) — see
 * App\Services\Audit\AuditActorContext.
 */
class AuditEvent extends Model
{
    /**
     * No `updated_at` column exists at all — see the migration. Eloquent
     * still auto-manages `created_at` on insert with this constant left
     * pointing at the real column name.
     */
    public const UPDATED_AT = null;

    protected $table = 'audit_events';

    /**
     * Deliberately excludes `id`, `uuid`, and `created_at` — the id is
     * database-assigned, the uuid is always generated fresh in creating()
     * regardless of what a caller supplies (see booted()), and created_at
     * is Eloquent-managed. A caller attempting to mass-assign any of the
     * three has that value silently ignored, not honored.
     */
    protected $fillable = [
        'event_category',
        'event_action',
        'subject_type',
        'subject_key',
        'subject_label',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'actor_roles',
        'actor_type',
        'old_values',
        'new_values',
        'changed_fields',
        'reason',
        'correlation_id',
        'ip_address',
        'user_agent',
        'route_name',
        'http_method',
        'status',
    ];

    protected $casts = [
        'actor_roles' => 'array',
        'actor_type' => AuditActorType::class,
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_fields' => 'array',
        'status' => AuditStatus::class,
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            // Always freshly generated — a caller-supplied uuid (via mass
            // assignment or direct attribute set) is never honored, so a
            // uuid can never be attacker- or caller-chosen.
            $event->uuid = (string) Str::uuid();
        });

        static::updating(function (): never {
            throw new AuditImmutableRecordException('Audit events cannot be updated once created.');
        });

        static::deleting(function (): never {
            throw new AuditImmutableRecordException('Audit events cannot be deleted.');
        });

        static::replicating(function (): never {
            throw new AuditImmutableRecordException('Audit events cannot be replicated.');
        });
    }

    /**
     * This model has no SoftDeletes trait, so delete() already performs a
     * real row deletion and is already blocked by the `deleting` hook
     * above — this override exists only so a direct forceDelete() call
     * (which would otherwise be an undefined-method fatal error, not a
     * clear domain exception) fails the same explicit, documented way.
     */
    public function forceDelete(): bool
    {
        throw new AuditImmutableRecordException('Audit events cannot be force-deleted.');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
