<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OMS Muwakha Families — map every existing family to its CURRENT Account, and
 * bring that one Account's name onto the canonical pattern.
 *
 * OWNERSHIP IS READ, NEVER INFERRED. The only ownership signal used here is
 * the explicit foreign key `muwakha_families.account_id`. Nothing matches on
 * martyr name, on account name or on account number — an Account that is not
 * pointed at by a family row is left completely alone, with no mapping
 * created, because guessing that it "looks like" a family's old account would
 * fabricate financial ownership. Such Accounts are reported for human review
 * instead (see docs/TASKS_LOG.md, 2026-08-17).
 *
 * NAME NORMALIZATION IS FENCED BY LEDGER ACTIVITY. Accounts created earlier in
 * this same uncommitted development session carry the older
 * `أسرة الشهيد {martyr} - {currency}` name. The currently linked Account of a
 * family is renamed to the canonical
 * `أسرة الشهيد {martyr} - {currency} - ({account_code})` ONLY when it has zero
 * `transaction_lines`. An Account with ledger activity is historical financial
 * data and is never renamed by a migration: its label is what appeared on the
 * statements and reports produced while it was in use. Accounts without an
 * `account_code` are also skipped — the canonical name requires one.
 *
 * IDEMPOTENT. Mappings are inserted only when absent; a name is written only
 * when it actually differs. Re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Trashed families included on purpose: ownership is durable, and a
        // soft-deleted family's Account still belongs to it.
        $families = DB::table('muwakha_families')
            ->select(['id', 'martyr_name', 'account_id', 'account_holder_name'])
            ->orderBy('id')
            ->get();

        foreach ($families as $family) {
            if ($family->account_id === null) {
                continue;
            }

            $this->mapCurrentAccount($family, $now);
            $this->normalizeCurrentAccountName($family);
        }
    }

    /**
     * Intentionally irreversible (no-op).
     *
     * The mappings are removed by dropping the table in
     * `2026_08_17_100000`. The renames cannot be undone, because the previous
     * name is not recoverable once overwritten — and re-deriving it would be
     * the same guesswork this migration refuses to do.
     */
    public function down(): void
    {
        // No-op by design — see the docblock above.
    }

    private function mapCurrentAccount(object $family, mixed $now): void
    {
        $exists = DB::table('muwakha_family_accounts')
            ->where('muwakha_family_id', $family->id)
            ->where('account_id', $family->account_id)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('muwakha_family_accounts')->insert([
            'muwakha_family_id' => $family->id,
            'account_id' => $family->account_id,
            'account_holder_name' => (string) $family->account_holder_name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reproduces MuwakhaAccountIdentity::accountNameFor() rather than calling
     * it, so this migration stays correct even if the helper changes later —
     * the same rule the reference-data migration follows for ProjectSuper's
     * code format.
     */
    private function normalizeCurrentAccountName(object $family): void
    {
        $account = DB::table('accounts')
            ->select(['id', 'name', 'account_code', 'currency_id'])
            ->where('id', $family->account_id)
            ->first();

        if ($account === null) {
            return;
        }

        $accountCode = trim((string) ($account->account_code ?? ''));

        if ($accountCode === '') {
            return;
        }

        // Ledger activity makes the name historical financial data.
        if (DB::table('transaction_lines')->where('account_id', $account->id)->exists()) {
            return;
        }

        $currencyName = trim((string) DB::table('currencies')
            ->where('id', $account->currency_id)
            ->value('name'));

        if ($currencyName === '') {
            return;
        }

        $canonical = 'أسرة الشهيد '.trim((string) $family->martyr_name)
            .' - '.$currencyName
            .' - ('.$accountCode.')';

        if ($account->name === $canonical) {
            return;
        }

        DB::table('accounts')->where('id', $account->id)->update(['name' => $canonical]);
    }
};
