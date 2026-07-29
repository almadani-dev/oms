<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `created_by`/`updated_by` exist on this table since Task 8.3's schema-drift
 * reconciliation (2026_07_28_120000), which deliberately reconciled the
 * COLUMNS only and left activating the behavior to the Audit Log work. OMS
 * Task 9B.3 activates it: HasUserTracking now populates both on every save
 * from this point forward. Existing rows are NOT backfilled — an always-NULL
 * historical value is the honest record that no tracked actor is known for
 * them, and inventing one would be a fabricated audit trail.
 *
 * Note that a balance change performed with increment()/decrement() writes
 * only `current_balance` in SQL, so it never rewrites `updated_by` — which is
 * correct: the actor behind a balance movement is recorded on the financial
 * workflow's own AuditEvent, not on the account row.
 */
class Account extends Model
{
    use HasUserTracking, SoftDeletes;

    protected $fillable = [
        'account_code',
        'name',
        'account_type_id',
        'bank_type_id',
        'currency_id',
        'current_balance',
        'is_active',
        'iban',
        'notes',
        'created_by',
        'updated_by',
    ];

    /**
     * Mirrors the column's own database default. `current_balance` is never
     * submitted by AccountForm (the field is disabled + dehydrated(false),
     * because a balance may only move through balanced entries), so without
     * this a freshly created Account carries a NULL balance in memory while
     * the stored row holds 0.00 — and the audit snapshot taken right after
     * that insert would record the in-memory NULL rather than the real value.
     */
    protected $attributes = [
        'current_balance' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active'       => 'boolean',
            'current_balance' => 'decimal:2',
        ];
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function bankType(): BelongsTo
    {
        return $this->belongsTo(BankType::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function projectCosts(): HasMany
    {
        return $this->hasMany(ProjectCost::class);
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
