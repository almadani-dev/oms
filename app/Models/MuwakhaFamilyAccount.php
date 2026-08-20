<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable ownership: this Account belongs, or once belonged, to this family.
 *
 * `muwakha_families.account_id` is the family's ONE CURRENT destination; this
 * model is the permanent membership record behind it, and it is the ONLY
 * scope an exact-Account reuse search is allowed to look through. Reuse is
 * strictly family-scoped because `accounts.account_code` is intentionally not
 * unique across OMS — two unrelated families may hold the same real bank
 * number, and their ledgers are separated only by `accounts.id`.
 *
 * Deliberately NOT an account-history event: no effective dates, no version
 * numbers, no ordering semantics. The `accounts` rows themselves preserve the
 * historical financial identity.
 *
 * `account_holder_name` is the one material identity field `accounts` has no
 * column for, so the value belonging to each Account is kept here. Currency,
 * account number, bank type and IBAN are never duplicated — `accounts` stays
 * authoritative.
 *
 * No SoftDeletes and no user tracking, matching MuwakhaFamilyProject: the
 * accountable actor lives on the mapping's own AuditEvent, and a mapping is
 * never removed by this feature at all — a family soft delete keeps every one
 * of them.
 */
class MuwakhaFamilyAccount extends Model
{
    protected $table = 'muwakha_family_accounts';

    protected $fillable = [
        'muwakha_family_id',
        'account_id',
        'account_holder_name',
    ];

    public function muwakhaFamily(): BelongsTo
    {
        return $this->belongsTo(MuwakhaFamily::class, 'muwakha_family_id');
    }

    /**
     * `withTrashed()` for the same reason MuwakhaFamily::account() uses it:
     * this feature never deletes an Account, but if one were removed out of
     * band the mapping must still resolve so the problem can be REPORTED
     * rather than silently disappearing from the reuse search.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }
}
