<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report builder for "ميزان المراجعة" (Trial Balance).
 *
 * Scoped to a single currency at a time: transaction_lines.debit_base/credit_base
 * are always the line's own-currency amount (never a converted company-base-currency
 * figure, despite the column names), so totals from accounts held in different
 * currencies must never be blended together.
 *
 * Reads transaction_lines/transactions/accounts. Never writes to them, and never
 * reads or writes accounts.current_balance.
 */
class TrialBalanceReportService
{
    private const TOLERANCE = 0.01;

    /**
     * @return array<string, mixed>
     */
    public function generate(
        string $dateFrom,
        string $dateTo,
        int $currencyId,
        ?int $accountTypeId,
        bool $includeZeroAccounts,
    ): array {
        $accounts = Account::query()
            ->where('currency_id', $currencyId)
            ->when($accountTypeId, fn ($q) => $q->where('account_type_id', $accountTypeId))
            ->with('accountType:id,name')
            ->orderBy('account_code')
            ->orderBy('name')
            ->get();

        $currency = Currency::find($currencyId);
        $currencyLabel = $this->formatCurrencyLabel($currency);
        $accountTypeLabel = $accountTypeId ? AccountType::find($accountTypeId)?->name : null;

        if ($accounts->isEmpty()) {
            return $this->emptyResult($currencyLabel, $currency?->code, $accountTypeLabel);
        }

        $aggregates = DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->where('tl.currency_id', $currencyId)
            ->whereIn('tl.account_id', $accounts->pluck('id'))
            ->whereBetween('t.transaction_time', [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay(),
            ])
            ->groupBy('tl.account_id')
            ->select([
                'tl.account_id',
                DB::raw('SUM(tl.debit_base) as total_debit'),
                DB::raw('SUM(tl.credit_base) as total_credit'),
            ])
            ->get()
            ->keyBy('account_id');

        $rows = [];

        foreach ($accounts as $account) {
            $aggregate = $aggregates->get($account->id);
            $totalDebit = (float) ($aggregate->total_debit ?? 0);
            $totalCredit = (float) ($aggregate->total_credit ?? 0);

            $isZero = abs($totalDebit) < self::TOLERANCE && abs($totalCredit) < self::TOLERANCE;

            if (! $includeZeroAccounts && $isZero) {
                continue;
            }

            $balance = $totalDebit - $totalCredit;

            $rows[] = [
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'account_type_name' => $account->accountType?->name,
                'currency_label' => $currencyLabel,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $balance,
                'nature' => $this->classifyNature($balance),
            ];
        }

        $grandDebit = array_sum(array_column($rows, 'total_debit'));
        $grandCredit = array_sum(array_column($rows, 'total_credit'));
        $difference = $grandDebit - $grandCredit;

        return [
            'rows' => $rows,
            'grand_debit' => $grandDebit,
            'grand_credit' => $grandCredit,
            'difference' => $difference,
            'is_balanced' => abs($difference) < self::TOLERANCE,
            'accounts_count' => count($rows),
            'currency_label' => $currencyLabel,
            'currency_code' => $currency?->code,
            'account_type_label' => $accountTypeLabel,
        ];
    }

    private function classifyNature(float $balance): string
    {
        if ($balance > self::TOLERANCE) {
            return 'مدين';
        }

        if ($balance < -self::TOLERANCE) {
            return 'دائن';
        }

        return 'متوازن';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(?string $currencyLabel, ?string $currencyCode, ?string $accountTypeLabel): array
    {
        return [
            'rows' => [],
            'grand_debit' => 0.0,
            'grand_credit' => 0.0,
            'difference' => 0.0,
            'is_balanced' => true,
            'accounts_count' => 0,
            'currency_label' => $currencyLabel,
            'currency_code' => $currencyCode,
            'account_type_label' => $accountTypeLabel,
        ];
    }

    private function formatCurrencyLabel(?Currency $currency): ?string
    {
        if (! $currency) {
            return null;
        }

        return trim($currency->name . ($currency->code ? " ({$currency->code})" : ''));
    }
}
