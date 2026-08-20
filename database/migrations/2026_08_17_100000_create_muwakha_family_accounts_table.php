<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Muwakha Families — durable family <-> Account ownership.
 *
 * WHAT THIS IS. A permanent record of "these Account ids belong, or once
 * belonged, to this family". `muwakha_families.account_id` remains the ONE
 * CURRENT destination; this table is what lets the system still know about
 * every Account the family used before that, so an exact historical Account
 * can be reused instead of duplicated.
 *
 * WHAT THIS IS NOT. It is deliberately NOT an account-change event log: there
 * are no effective dates, no version numbers, no payment-destination history,
 * no snapshots and no ordering semantics. Nothing here records WHEN a family
 * moved between accounts or WHY. The `accounts` rows themselves preserve the
 * historical financial identity — every `transaction_line` references
 * `accounts.id`, so an Account that has been paid through is already an
 * immutable historical record and needs no shadow copy.
 *
 * WHY `account_holder_name` LIVES HERE. It is the single piece of material
 * account identity that `accounts` has no column for (the person the
 * bank/wallet is registered to, frequently not the guardian). Because it is
 * part of the identity an exact-reuse search compares, the value that belonged
 * to each Account has to survive on the mapping. Currency, account number,
 * bank type and IBAN are deliberately NOT duplicated here — `accounts` stays
 * authoritative for all four.
 *
 * CONSTRAINTS, AND ONE DELIBERATELY ABSENT. `UNIQUE(muwakha_family_id,
 * account_id)` — one family may map an Account exactly once, which is what
 * makes reuse idempotent. There is deliberately NO uniqueness on
 * (family, currency): a family may legitimately hold several Accounts in the
 * same currency (e.g. two different ILS bank numbers), so "family + currency"
 * is not an identity in this design.
 *
 * DELETE SEMANTICS. `cascadeOnDelete` on the family mirrors
 * `muwakha_family_projects` and is a safety net for a hard delete only — the
 * ordinary family deletion is a SOFT delete, so mappings survive it intact,
 * which is the approved behaviour. `restrictOnDelete` on the account matches
 * `muwakha_families.account_id`: an Account a family has ever used must never
 * be removable out from under that record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muwakha_family_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('muwakha_family_id')->constrained('muwakha_families')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            // The holder name that belongs to THIS Account identity — not
            // necessarily the family's current one.
            $table->string('account_holder_name');

            $table->timestamps();

            $table->unique(
                ['muwakha_family_id', 'account_id'],
                'muwakha_family_accounts_family_account_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muwakha_family_accounts');
    }
};
