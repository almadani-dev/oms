<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Muwakha Families (مشروع المؤاخاة) — the family/beneficiary entity.
 *
 * A family owns exactly ONE OMS Account, created automatically alongside it.
 * The payment/bank details themselves (account number, bank type, currency,
 * IBAN) deliberately live ONLY on `accounts` and are never duplicated here —
 * `accounts` stays the single authoritative payment-destination record, and
 * the existing Execution Payment workflow keeps selecting that Account as its
 * beneficiary with no change to any accounting semantics.
 *
 * `account_holder_name` is the one payment-adjacent field owned by the family
 * rather than the account: it is the person the bank/wallet is registered to,
 * which is frequently NOT the guardian and is never `accounts.name` (that is
 * always the derived "أسرة الشهيد {martyr_name} - {currency display name}").
 *
 * The account currency is operator-chosen and lives ONLY on
 * `accounts.currency_id`. Changing it does not re-denominate the existing
 * Account: MuwakhaFamilyService creates a new Account and repoints
 * `account_id` here, leaving the previous Account and its ledger intact. That
 * is why there is deliberately no account-history table — the superseded
 * Accounts themselves are the history.
 *
 * There is no `age` column by design. Martyr age is a pure function of
 * `martyr_date_of_birth` and `martyrdom_date`, computed in completed years AT
 * THE DATE OF MARTYRDOM (never against today), so storing it would create a
 * value that silently disagrees with its own inputs after any correction.
 *
 * SOFT DELETE + UNIQUE INTERACTION (deliberate, documented):
 * `martyr_national_id` carries a plain UNIQUE index, which MySQL enforces
 * across soft-deleted rows too. Deleting a family therefore reserves that
 * national id permanently, and this feature ships no Restore UI by design.
 * That is the strict reading of "unique across family records" and is the
 * safer failure: a duplicate martyr record is a data-integrity problem, while
 * a blocked re-entry is a visible, recoverable one requiring a deliberate
 * decision. A composite unique on (martyr_national_id, deleted_at) was
 * rejected because MySQL treats NULLs as distinct, which would permit
 * unlimited duplicate LIVE rows — the exact opposite of the requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muwakha_families', function (Blueprint $table) {
            $table->id();

            // --- بيانات الشهيد ---
            $table->string('martyr_name');
            // String, never an integer: national ids may carry leading zeros
            // that an integer column would silently destroy.
            $table->string('martyr_national_id', 50)->unique();
            $table->date('martyr_date_of_birth')->nullable();
            $table->date('martyrdom_date');
            // unsignedInteger enforces the ">= 0" rule at the database level;
            // 0 is a valid, meaningful value (a martyr with no children).
            $table->unsignedInteger('children_count')->default(0);

            // --- بيانات الوصي ---
            $table->string('guardian_name');
            $table->string('guardian_national_id', 50)->nullable();
            $table->date('guardian_date_of_birth')->nullable();
            // String, never an integer: preserves leading zeros and any
            // country/format prefix the staff enter.
            $table->string('guardian_phone', 50);

            // --- بيانات الحساب ---
            $table->string('account_holder_name');
            // One family owns one dedicated Account, enforced by UNIQUE.
            // restrictOnDelete: an Account backing a live family must never be
            // removed out from under it. Family deletion deliberately leaves
            // the Account completely untouched (see MuwakhaFamilyService).
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Supports the list table's default sort and the guardian/martyr
            // name search paths. Not speculative: both are real query paths in
            // MuwakhaFamiliesTable.
            $table->index('martyr_name', 'muwakha_families_martyr_name_index');
            $table->index('guardian_name', 'muwakha_families_guardian_name_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muwakha_families');
    }
};
