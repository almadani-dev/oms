<?php

namespace App\Observers;

use App\Models\Account;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionState;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjector;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the "تقرير الحركات المالية الشامل" projection's copy of each line's
 * account type current. An account's type is editable, and the report
 * filters and aggregates by it, so when it changes every transaction of that
 * account is re-derived from the ledger — inside the edit's own DB
 * transaction when there is one (the account edit and the projection then
 * commit or roll back together).
 *
 * Nothing else about an account is stored in the projection: names, codes,
 * notes and soft deletion are all read at report time.
 */
class AccountReportProjectionObserver
{
    public function updated(Account $account): void
    {
        if (! $account->wasChanged('account_type_id')) {
            return;
        }

        $sync = fn () => app(ComprehensiveReportProjector::class)->syncAccount((int) $account->id);

        if (DB::transactionLevel() > 0) {
            $sync();

            return;
        }

        try {
            DB::transaction($sync);
        } catch (\Throwable $e) {
            // The account change is already committed: never let the report
            // show figures built on the old type.
            app(ComprehensiveReportProjectionState::class)->markInvalid('Account '.$account->id.' type change could not be projected.');

            throw $e;
        }
    }
}
