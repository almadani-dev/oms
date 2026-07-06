<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use App\Models\Project;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only report builder for "تقرير الحركات المالية الشامل"
 * (Comprehensive Financial Transactions / general journal report).
 *
 * Lists every non-deleted transaction line inside every non-deleted
 * transaction in the period — one row per transaction_line, so one
 * transaction intentionally spans multiple rows.
 *
 * transaction_lines.debit_base/credit_base are always the line's own-currency
 * amount (never a converted company-base-currency figure, despite the column
 * names), so totals are only ever aggregated per currency — no blended
 * multi-currency grand total is ever produced.
 *
 * Reads transaction_lines/transactions/accounts and lookup tables. Never
 * writes to any of them, and never reads or writes accounts.current_balance.
 */
class ComprehensiveFinancialTransactionsReportService
{
    private const TOLERANCE = 0.01;

    /**
     * @param array<int, int> $currencyIds empty array = all currencies
     * @return array<string, mixed>
     */
    public function generate(
        string $dateFrom,
        string $dateTo,
        array $currencyIds = [],
        ?int $transactionSuperTypeId = null,
        ?int $transactionTypeId = null,
        ?int $accountId = null,
        ?int $accountTypeId = null,
        ?int $projectId = null,
    ): array {
        $lines = DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->join('accounts as a', 'a.id', '=', 'tl.account_id')
            ->leftJoin('accounts_type as at', 'at.id', '=', 'a.account_type_id')
            ->leftJoin('currencies as c', 'c.id', '=', 'tl.currency_id')
            ->leftJoin('transactions_types as tt', 'tt.id', '=', 't.transaction_type_id')
            ->leftJoin('transaction_super_types as tst', 'tst.id', '=', 'tt.transaction_super_type_id')
            ->leftJoin('projects_costs as pc', function ($join) {
                $join->on('pc.id', '=', 'tl.project_cost_id')->whereNull('pc.deleted_at');
            })
            ->leftJoin('projects as p', function ($join) {
                $join->on('p.id', '=', 'pc.project_id')->whereNull('p.deleted_at');
            })
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereNull('a.deleted_at')
            ->whereBetween('t.transaction_time', [
                Carbon::parse($dateFrom)->startOfDay(),
                Carbon::parse($dateTo)->endOfDay(),
            ])
            ->when($currencyIds !== [], fn ($q) => $q->whereIn('tl.currency_id', $currencyIds))
            ->when($transactionSuperTypeId, fn ($q) => $q->where('tt.transaction_super_type_id', $transactionSuperTypeId))
            ->when($transactionTypeId, fn ($q) => $q->where('t.transaction_type_id', $transactionTypeId))
            ->when($accountId, fn ($q) => $q->where('tl.account_id', $accountId))
            ->when($accountTypeId, fn ($q) => $q->where('a.account_type_id', $accountTypeId))
            ->when($projectId, fn ($q) => $q->where('pc.project_id', $projectId))
            ->orderBy('t.transaction_time')
            ->orderBy('t.id')
            ->orderBy('tl.id')
            ->select([
                't.id as transaction_id',
                't.transaction_number',
                't.transaction_time',
                't.description as transaction_description',
                'tst.name as super_type_name',
                'tt.name as type_name',
                'tl.id as line_id',
                'tl.notes as line_notes',
                'tl.debit_base',
                'tl.credit_base',
                'a.account_code',
                'a.name as account_name',
                'at.name as account_type_name',
                'c.name as currency_name',
                'c.code as currency_code',
                'p.name as project_name',
                'u.name as created_by_name',
            ])
            ->get();

        $rows = [];
        $transactionIds = [];
        $currencySummaries = [];
        $categorySummaries = [];
        $typeSummaries = [];

        foreach ($lines as $line) {
            $debit = (float) $line->debit_base;
            $credit = (float) $line->credit_base;

            $currencyLabel = $this->currencyLabel($line->currency_name, $line->currency_code);
            $currencyShort = $line->currency_code ?: ($line->currency_name ?: 'غير محدد');
            $category = $line->super_type_name ?: 'غير محدد';
            $type = $line->type_name ?: 'غير محدد';

            $transactionIds[$line->transaction_id] = true;

            $rows[] = [
                'date' => Carbon::parse($line->transaction_time)->format('Y-m-d'),
                'reference' => $line->transaction_number ?: ('قيد #' . $line->transaction_id),
                'category' => $category,
                'type' => $type,
                'description' => $this->description($line->transaction_description, $line->line_notes),
                'account' => trim(($line->account_code ? $line->account_code . ' - ' : '') . $line->account_name),
                'account_type' => $line->account_type_name ?: 'غير محدد',
                'project' => $line->project_name ?: 'غير مرتبط بمشروع',
                'currency' => $currencyLabel,
                'debit' => $debit,
                'credit' => $credit,
                'created_by' => $line->created_by_name ?: '-',
            ];

            // B) per-currency totals — never blended across currencies.
            if (! isset($currencySummaries[$currencyLabel])) {
                $currencySummaries[$currencyLabel] = [
                    'currency' => $currencyLabel,
                    'total_debit' => 0.0,
                    'total_credit' => 0.0,
                ];
            }
            $currencySummaries[$currencyLabel]['total_debit'] += $debit;
            $currencySummaries[$currencyLabel]['total_credit'] += $credit;

            $this->accumulateGrouped($categorySummaries, $category, (int) $line->transaction_id, $currencyShort, $debit, $credit);
            $this->accumulateGrouped($typeSummaries, $type, (int) $line->transaction_id, $currencyShort, $debit, $credit);
        }

        foreach ($currencySummaries as &$summary) {
            $summary['difference'] = $summary['total_debit'] - $summary['total_credit'];
            $summary['is_balanced'] = abs($summary['difference']) < self::TOLERANCE;
        }
        unset($summary);

        return [
            'rows' => $rows,
            'currency_summaries' => array_values($currencySummaries),
            'category_summaries' => $this->finalizeGrouped($categorySummaries),
            'type_summaries' => $this->finalizeGrouped($typeSummaries),
            'transaction_count' => count($transactionIds),
            'line_count' => count($rows),
            'currencies_count' => count($currencySummaries),
            'filter_labels' => $this->filterLabels(
                $currencyIds,
                $transactionSuperTypeId,
                $transactionTypeId,
                $accountId,
                $accountTypeId,
                $projectId,
            ),
        ];
    }

    /**
     * Adds one line to a category/type summary bucket, keeping debit/credit
     * totals separated per currency inside the bucket. Transactions are
     * counted by distinct id (a transaction with several lines still counts
     * once), while the financial totals stay line-based.
     *
     * @param array<string, array<string, mixed>> $summaries
     */
    private function accumulateGrouped(array &$summaries, string $name, int $transactionId, string $currency, float $debit, float $credit): void
    {
        if (! isset($summaries[$name])) {
            $summaries[$name] = [
                'name' => $name,
                'line_count' => 0,
                'transaction_ids' => [],
                'currencies' => [],
            ];
        }

        $summaries[$name]['line_count']++;
        $summaries[$name]['transaction_ids'][$transactionId] = true;

        if (! isset($summaries[$name]['currencies'][$currency])) {
            $summaries[$name]['currencies'][$currency] = [
                'currency' => $currency,
                'total_debit' => 0.0,
                'total_credit' => 0.0,
            ];
        }

        $summaries[$name]['currencies'][$currency]['total_debit'] += $debit;
        $summaries[$name]['currencies'][$currency]['total_credit'] += $credit;
    }

    /**
     * @param array<string, array<string, mixed>> $summaries
     * @return array<int, array<string, mixed>>
     */
    private function finalizeGrouped(array $summaries): array
    {
        return array_values(array_map(function (array $summary) {
            $summary['transaction_count'] = count($summary['transaction_ids']);
            unset($summary['transaction_ids']);
            $summary['currencies'] = array_values($summary['currencies']);

            return $summary;
        }, $summaries));
    }

    private function description(?string $transactionDescription, ?string $lineNotes): string
    {
        $parts = array_filter([
            trim((string) $transactionDescription),
            trim((string) $lineNotes),
        ]);

        return $parts === [] ? '-' : implode(' — ', $parts);
    }

    private function currencyLabel(?string $name, ?string $code): string
    {
        if (! $name && ! $code) {
            return 'غير محدد';
        }

        return trim(($name ?: '') . ($code ? " ({$code})" : ''));
    }

    /**
     * Human-readable snapshot of the applied optional filters, for display
     * above the results and for the future export phase.
     *
     * @param array<int, int> $currencyIds
     * @return array<string, string>
     */
    private function filterLabels(
        array $currencyIds,
        ?int $transactionSuperTypeId,
        ?int $transactionTypeId,
        ?int $accountId,
        ?int $accountTypeId,
        ?int $projectId,
    ): array {
        $labels = [];

        if ($currencyIds !== []) {
            $labels['العملات'] = Currency::withTrashed()
                ->whereIn('id', $currencyIds)
                ->get(['name', 'code'])
                ->map(fn ($currency) => $this->currencyLabel($currency->name, $currency->code))
                ->implode('، ');
        }

        if ($transactionSuperTypeId) {
            $labels['تصنيف المعاملة'] = TransactionSuperType::withTrashed()->find($transactionSuperTypeId)?->name ?? '-';
        }

        if ($transactionTypeId) {
            $labels['نوع المعاملة'] = TransactionType::withTrashed()->find($transactionTypeId)?->name ?? '-';
        }

        if ($accountId) {
            $account = Account::withTrashed()->find($accountId);
            $labels['الحساب'] = $account
                ? trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name)
                : '-';
        }

        if ($accountTypeId) {
            $labels['نوع الحساب'] = AccountType::withTrashed()->find($accountTypeId)?->name ?? '-';
        }

        if ($projectId) {
            $labels['المشروع'] = Project::withTrashed()->find($projectId)?->name ?? '-';
        }

        return $labels;
    }
}
