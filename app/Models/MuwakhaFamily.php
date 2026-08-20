<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A Muwakha (مشروع المؤاخاة) beneficiary family.
 *
 * `account_id` is the family's ONE CURRENT payment destination, created
 * automatically with it by MuwakhaFamilyService. Payment details themselves
 * (account number, bank type, currency, IBAN) live only on that Account — this
 * model never duplicates them — so the existing Execution Payment workflow can
 * select the Account as its beneficiary with no change to any accounting
 * semantics.
 *
 * A family may own SEVERAL Accounts over time, including several in the same
 * currency: a material account change never rewrites the previous Account (its
 * ledger already references it), so it either resolves back to an Account the
 * family already owns or produces a new one. `familyAccounts()` is the durable
 * record of all of them; `account_id` is only the current one.
 *
 * `martyrAgeAtMartyrdom()` is computed, never stored: age is a pure function
 * of two dates already on the row, and persisting it would create a value
 * that could silently disagree with its own inputs after a correction.
 */
class MuwakhaFamily extends Model
{
    use HasUserTracking, SoftDeletes;

    protected $table = 'muwakha_families';

    protected $fillable = [
        'martyr_name',
        'martyr_national_id',
        'martyr_date_of_birth',
        'martyrdom_date',
        'children_count',
        'guardian_name',
        'guardian_national_id',
        'guardian_date_of_birth',
        'guardian_phone',
        'account_holder_name',
        'account_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'martyr_date_of_birth'   => 'date',
            'martyrdom_date'         => 'date',
            'guardian_date_of_birth' => 'date',
            'children_count'         => 'integer',
        ];
    }

    /**
     * The dedicated payment Account this family owns.
     *
     * `withTrashed()` is deliberate: the account is never deleted by this
     * feature, but if one were ever removed out of band the relation must
     * still resolve so the UI can REPORT the problem instead of silently
     * rendering an empty account. MuwakhaFamilyService fails closed on a
     * missing/trashed account rather than repairing or replacing it.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function familyProjects(): HasMany
    {
        return $this->hasMany(MuwakhaFamilyProject::class, 'muwakha_family_id');
    }

    /**
     * Every Account that belongs, or once belonged, to this family — the
     * durable ownership record behind `account_id`.
     *
     * This is the ONLY scope an exact-Account reuse search may look through:
     * `accounts.account_code` is intentionally not unique across OMS, so a
     * global search could hand this family another family's ledger. It is not
     * an account-history log — it carries no dates, versions or ordering.
     */
    public function familyAccounts(): HasMany
    {
        return $this->hasMany(MuwakhaFamilyAccount::class, 'muwakha_family_id');
    }

    /**
     * The family's mapped Accounts that are safe to DISPLAY, ordered for the
     * View page: the current Account first, then the previous ones with the
     * newest mapping first.
     *
     * Soft-deleted Accounts are excluded entirely. That is a DISPLAY FILTER
     * only — the mapping row is never removed and the Account is never
     * restored, reactivated or otherwise touched. `familyAccounts()->account()`
     * carries `withTrashed()` on purpose (so a trashed Account still resolves
     * where the code needs to report a problem), which is exactly why this
     * method has to exclude them explicitly rather than relying on a scope.
     *
     * One query plus two eager loads regardless of how many Accounts a family
     * has accumulated — no N+1.
     *
     * @return Collection<int, MuwakhaFamilyAccount>
     */
    public function visibleAccountLinks(): Collection
    {
        $links = $this->familyAccounts()
            ->whereHas('account', fn ($query) => $query->whereNull('accounts.deleted_at'))
            ->with(['account.currency', 'account.bankType'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            // A mapping whose Account cannot be resolved at all is dropped for
            // the same reason: this section shows only what it can state.
            ->filter(fn (MuwakhaFamilyAccount $link): bool => $link->account !== null && ! $link->account->trashed())
            // The owning family is already loaded — it is `$this`. Attaching it
            // lets a caller ask each mapping whether it is the current one
            // without re-querying the family once per row.
            ->each(fn (MuwakhaFamilyAccount $link) => $link->setRelation('muwakhaFamily', $this));

        [$current, $previous] = $links->partition(
            fn (MuwakhaFamilyAccount $link): bool => (int) $link->account_id === (int) $this->account_id,
        );

        return $current->concat($previous)->values();
    }

    /**
     * The ownership record for the family's CURRENT Account, which is where
     * the current `account_holder_name` associated with that Account lives.
     */
    public function currentFamilyAccount(): ?MuwakhaFamilyAccount
    {
        if ($this->account_id === null) {
            return null;
        }

        return $this->familyAccounts()->where('account_id', $this->account_id)->first();
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'muwakha_family_projects', 'muwakha_family_id', 'project_id')
            ->withPivot(['id', 'card_code'])
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The martyr's age in COMPLETED YEARS at the date of martyrdom — never
     * against today, because the person's age stopped advancing then.
     *
     * Returns null when either date is missing (date of birth is optional) or
     * when martyrdom precedes birth, which validation rejects but which a row
     * written before this feature's validation existed could still contain.
     */
    public function martyrAgeAtMartyrdom(): ?int
    {
        $birth = $this->martyr_date_of_birth;
        $death = $this->martyrdom_date;

        if (! $birth instanceof Carbon || ! $death instanceof Carbon) {
            return null;
        }

        if ($death->lessThan($birth)) {
            return null;
        }

        return (int) $birth->diffInYears($death);
    }
}
