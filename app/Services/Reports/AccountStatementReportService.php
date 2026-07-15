<?php

namespace App\Services\Reports;

use App\Enums\TransactionLineRole;
use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report builder for "تقرير كشف الحساب" (Account Statement).
 *
 * Reads transaction_lines joined to transactions/currencies/transaction types.
 * Never writes to accounts, transactions, or transaction_lines, and never
 * touches accounts.current_balance.
 */
class AccountStatementReportService
{
    /**
     * @return array<string, mixed>
     */
    public function generate(int $accountId, ?string $dateFrom, ?string $dateTo): array
    {
        // accountType/bankType are only used for export headers (not for any
        // balance calculation) — eager loaded here to avoid extra queries.
        $account = Account::with(['currency', 'accountType', 'bankType'])->findOrFail($accountId);

        $openingBalance = $this->calculateOpeningBalance($accountId, $dateFrom);

        $lines = $this->periodLinesQuery($accountId, $dateFrom, $dateTo)->get();

        $running = $openingBalance;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $currencyCodes = [];
        $rows = [];

        foreach ($lines as $line) {
            $isDebit = (float) $line->debit_base > 0;
            $amount = (float) $line->amount_currency;
            $debit = $isDebit ? $amount : 0.0;
            $credit = $isDebit ? 0.0 : $amount;

            $running += $debit - $credit;
            $totalDebit += $debit;
            $totalCredit += $credit;

            if ($line->line_currency_code) {
                $currencyCodes[$line->line_currency_code] = true;
            }

            $rows[] = [
                'date' => $line->transaction_time,
                'transaction_number' => $line->transaction_number,
                'type_name' => $line->type_name,
                // t.description = وصف العملية المالية.
                'description' => $line->description,
                'notes' => $line->line_notes,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
                'currency_code' => $line->line_currency_code,
                // Approved audit metadata — display-only, never used for calculations.
                'line_role_label' => TransactionLineRole::labelFor($line->line_role) ?? '—',
                'line_description' => $this->displayOrDash($line->line_description),
            ];
        }

        // Opening balance may also be built from lines in a different currency
        // than the account's own — surface that in the mixed-currency check too.
        $openingCurrencyCodes = $this->distinctCurrencyCodesBefore($accountId, $dateFrom);
        $allCurrencyCodes = array_unique([...array_keys($currencyCodes), ...$openingCurrencyCodes]);

        return [
            'account' => $account,
            'currency_code' => $account->currency?->code,
            'opening_balance' => $openingBalance,
            'closing_balance' => $running,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'movements_count' => count($rows),
            'rows' => $rows,
            'has_mixed_currencies' => count($allCurrencyCodes) > 1,
        ];
    }

    protected function baseLineQuery(int $accountId): \Illuminate\Database\Query\Builder
    {
        return DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->where('tl.account_id', $accountId)
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at');
    }

    protected function calculateOpeningBalance(int $accountId, ?string $dateFrom): float
    {
        if (! $dateFrom) {
            return 0.0;
        }

        $totals = $this->baseLineQuery($accountId)
            ->where('t.transaction_time', '<', Carbon::parse($dateFrom)->startOfDay())
            ->selectRaw('SUM(CASE WHEN tl.debit_base > 0 THEN tl.amount_currency ELSE 0 END) as debit_sum')
            ->selectRaw('SUM(CASE WHEN tl.credit_base > 0 THEN tl.amount_currency ELSE 0 END) as credit_sum')
            ->first();

        return (float) ($totals->debit_sum ?? 0) - (float) ($totals->credit_sum ?? 0);
    }

    /**
     * @return array<int, string>
     */
    protected function distinctCurrencyCodesBefore(int $accountId, ?string $dateFrom): array
    {
        if (! $dateFrom) {
            return [];
        }

        return $this->baseLineQuery($accountId)
            ->join('currencies as cur', 'cur.id', '=', 'tl.currency_id')
            ->where('t.transaction_time', '<', Carbon::parse($dateFrom)->startOfDay())
            ->distinct()
            ->pluck('cur.code')
            ->filter()
            ->all();
    }

    protected function periodLinesQuery(int $accountId, ?string $dateFrom, ?string $dateTo): \Illuminate\Database\Query\Builder
    {
        return $this->baseLineQuery($accountId)
            ->leftJoin('transactions_types as tt', 'tt.id', '=', 't.transaction_type_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'tl.currency_id')
            ->when($dateFrom, fn ($q) => $q->where('t.transaction_time', '>=', Carbon::parse($dateFrom)->startOfDay()))
            ->when($dateTo, fn ($q) => $q->where('t.transaction_time', '<=', Carbon::parse($dateTo)->endOfDay()))
            ->orderBy('t.transaction_time')
            ->orderBy('tl.id')
            ->select([
                't.transaction_time',
                't.transaction_number',
                'tt.name as type_name',
                't.description',
                'tl.notes as line_notes',
                'tl.line_role',
                'tl.description as line_description',
                'tl.amount_currency',
                'tl.debit_base',
                'tl.credit_base',
                'cur.code as line_currency_code',
            ]);
    }

    /**
     * Approved historical-display convention: a genuinely NULL/blank value
     * (unclassified or pre-dating the description/role feature) renders as "—".
     */
    private function displayOrDash(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : '—';
    }
}
