<?php

namespace App\Services\Reports;

use App\Enums\TransactionLineRole;
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
     * Financial source records that point AT a transaction via their own
     * nullable transaction_id FK. There is no polymorphic source pointer on
     * transactions, and two of these (receipts, budget payments) are
     * one-to-many — joining them into the main lines query would fan the
     * result out and duplicate debit/credit lines, corrupting every summary.
     *
     * They are therefore pre-fetched separately, in exactly one bounded
     * whereIn() query per table, and merged in PHP. See sourceNotesByTransaction().
     *
     * @var array<int, array{0: string, 1: string}> [table, Arabic note label]
     */
    private const SOURCE_NOTE_TABLES = [
        ['general_expenses', 'ملاحظات المصروف العام'],
        ['general_exchanges', 'ملاحظات مبلغ الصرف'],
        ['project_cost_receipts', 'ملاحظات المبلغ المستلم'],
        ['project_cost_budgets_payments', 'ملاحظات دفعة الصرف'],
        ['project_cost_budgets', 'ملاحظات موازنة التكلفة'],
    ];

    /**
     * @param array<int, int> $currencyIds empty array = all currencies
     * @param array<int, int> $accountIds empty array = all accounts ("كل الحسابات")
     * @return array<string, mixed>
     */
    public function generate(
        string $dateFrom,
        string $dateTo,
        array $currencyIds = [],
        ?int $transactionSuperTypeId = null,
        ?int $transactionTypeId = null,
        array $accountIds = [],
        ?int $accountTypeId = null,
        ?int $projectId = null,
    ): array {
        $lines = DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->join('accounts as a', 'a.id', '=', 'tl.account_id')
            ->leftJoin('accounts_type as at', 'at.id', '=', 'a.account_type_id')
            // Unguarded like `at`/`c`/`tt` below: a soft-deleted bank type must
            // still label the historical lines that were posted against it.
            ->leftJoin('bank_types as bt', 'bt.id', '=', 'a.bank_type_id')
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
            ->when($accountIds !== [], fn ($q) => $q->whereIn('tl.account_id', $accountIds))
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
                't.notes as transaction_notes',
                'tst.id as super_type_id',
                'tst.name as super_type_name',
                'tt.id as type_id',
                'tt.name as type_name',
                'tl.id as line_id',
                'tl.notes as line_notes',
                'tl.line_role',
                'tl.description as line_description',
                'tl.debit_base',
                'tl.credit_base',
                'a.account_code',
                'a.name as account_name',
                'at.name as account_type_name',
                'a.notes as account_notes',
                'bt.name as bank_type_name',
                'pc.notes as project_cost_notes',
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
                'transaction_id' => (int) $line->transaction_id,
                'line_id' => (int) $line->line_id,
                'date' => Carbon::parse($line->transaction_time)->format('Y-m-d'),
                'reference' => $line->transaction_number ?: ('قيد #' . $line->transaction_id),
                'category' => $category,
                'category_key' => $this->groupKey($line->super_type_id),
                'type' => $type,
                'type_key' => $this->groupKey($line->type_id),
                'account' => trim(($line->account_code ? $line->account_code . ' - ' : '') . $line->account_name),
                'bank_type' => $line->bank_type_name ?: '—',
                'account_type' => $line->account_type_name ?: 'غير محدد',
                'project' => $line->project_name ?: 'غير مرتبط بمشروع',
                'currency' => $currencyLabel,
                'debit' => $debit,
                'credit' => $credit,
                'created_by' => $line->created_by_name ?: '-',
                // Approved audit metadata — display-only, never used for calculations.
                // transactions.description is rendered in exactly one column
                // ("البيان"); it is never merged into the notes collection.
                'transaction_description' => $this->displayOrDash($line->transaction_description),
                'line_role_label' => TransactionLineRole::labelFor($line->line_role) ?? '—',
                'line_description' => $this->displayOrDash($line->line_description),
                // Filled in after the loop by attachNotes(); every entry keeps
                // its own source label so a note's origin is never hidden, and
                // identical text from two different records stays as two entries.
                'notes' => $this->ownNotes($line),
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

            $this->accumulateGrouped($categorySummaries, $category, $this->groupKey($line->super_type_id), (int) $line->transaction_id, $currencyShort, $debit, $credit);
            $this->accumulateGrouped($typeSummaries, $type, $this->groupKey($line->type_id), (int) $line->transaction_id, $currencyShort, $debit, $credit);
        }

        // Source-record notes: one bounded whereIn() per source table (5 max,
        // and zero when the period is empty), merged in PHP. Deliberately run
        // AFTER the accumulation loop so no summary, total or currency bucket
        // can be affected by it.
        $this->attachSourceNotes($rows, array_map('intval', array_keys($transactionIds)));

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
                $accountIds,
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
     * Buckets are keyed by the underlying lookup ID ($key), never by display
     * text. Neither transaction_super_types.name nor transactions_types.name
     * carries a unique constraint or a unique validation rule, so two distinct
     * classifications may share a name; keying by name would silently merge
     * them into one row whose totals no consumer could decompose again.
     *
     * Keying by ID also makes bucket <-> rows exactly 1:1, which is what lets
     * the UI filter a classification's detail rows by row['category_key']
     * and a transaction type's detail rows by row['type_key'], and be certain
     * the rows it shows are precisely the rows behind the totals.
     * 'name' is carried alongside for display only.
     *
     * @param array<string, array<string, mixed>> $summaries
     */
    private function accumulateGrouped(array &$summaries, string $name, string $key, int $transactionId, string $currency, float $debit, float $credit): void
    {
        if (! isset($summaries[$key])) {
            $summaries[$key] = [
                'name' => $name,
                'key' => $key,
                'line_count' => 0,
                'transaction_ids' => [],
                'currencies' => [],
            ];
        }

        $summaries[$key]['line_count']++;
        $summaries[$key]['transaction_ids'][$transactionId] = true;

        if (! isset($summaries[$key]['currencies'][$currency])) {
            $summaries[$key]['currencies'][$currency] = [
                'currency' => $currency,
                'total_debit' => 0.0,
                'total_credit' => 0.0,
            ];
        }

        $summaries[$key]['currencies'][$currency]['total_debit'] += $debit;
        $summaries[$key]['currencies'][$currency]['total_credit'] += $credit;
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

    /**
     * Stable UI identifier for a category/type bucket. Lookup rows are never
     * hard-deleted, so the id is stable across reloads; unclassified lines
     * share the explicit 'none' bucket.
     */
    private function groupKey(mixed $id): string
    {
        return $id === null ? 'none' : 'id-' . (int) $id;
    }

    /**
     * Notes that live on the line itself or on entities already joined into
     * the main query — free, no extra round trip.
     *
     * 'scope' says where the note belongs: 'transaction' notes describe the
     * whole entry (and therefore repeat on each of its lines), 'line' notes
     * belong to this one line. The Word export uses it to place each note
     * correctly; the screen and Excel show them all together.
     *
     * @return array<int, array{label: string, text: string, scope: string}>
     */
    private function ownNotes(object $line): array
    {
        return array_values(array_filter([
            $this->note('ملاحظات المعاملة', $line->transaction_notes, 'transaction'),
            $this->note('ملاحظات سطر القيد', $line->line_notes, 'line'),
            // accounts is inner-joined on tl.account_id and already filtered by
            // whereNull('a.deleted_at'), so the account behind a line is always
            // present and unambiguous — this costs no extra query.
            //
            // bank_types.notes is deliberately NOT collected: it describes the
            // lookup row, not this movement.
            $this->note('ملاحظات الحساب', $line->account_notes, 'line'),
            $this->note('ملاحظات بند التكلفة', $line->project_cost_notes, 'line'),
        ]));
    }

    /**
     * @return array{label: string, text: string, scope: string}|null
     */
    private function note(string $label, ?string $text, string $scope): ?array
    {
        $trimmed = trim((string) $text);

        return $trimmed === '' ? null : ['label' => $label, 'text' => $trimmed, 'scope' => $scope];
    }

    /**
     * Merges the pre-fetched source-record notes onto every row of the
     * transaction they belong to. Pure in-memory work — no query runs here.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $transactionIds
     */
    private function attachSourceNotes(array &$rows, array $transactionIds): void
    {
        $notesByTransaction = $this->sourceNotesByTransaction($transactionIds);

        if ($notesByTransaction === []) {
            return;
        }

        foreach ($rows as &$row) {
            $extra = $notesByTransaction[$row['transaction_id']] ?? [];

            if ($extra !== []) {
                $row['notes'] = array_merge($row['notes'], $extra);
            }
        }
        unset($row);
    }

    /**
     * At most one bounded query per source table (5 total, regardless of how
     * many rows or transactions the report returned) — never N+1, and never
     * a join against the main lines query, which would duplicate lines.
     *
     * Soft-deleted source records contribute nothing: a deleted record is not
     * a historical label, unlike the lookup tables joined above.
     *
     * @param array<int, int> $transactionIds
     * @return array<int, array<int, array{label: string, text: string, scope: string}>>
     */
    private function sourceNotesByTransaction(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $map = [];

        foreach (self::SOURCE_NOTE_TABLES as [$table, $label]) {
            $records = DB::table($table)
                ->whereIn('transaction_id', $transactionIds)
                ->whereNull('deleted_at')
                ->whereNotNull('notes')
                ->orderBy('id')
                ->get(['transaction_id', 'notes']);

            foreach ($records as $record) {
                $note = $this->note($label, $record->notes, 'transaction');

                if ($note !== null) {
                    $map[(int) $record->transaction_id][] = $note;
                }
            }
        }

        return $map;
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
     * @param array<int, int> $accountIds
     * @return array<string, string>
     */
    private function filterLabels(
        array $currencyIds,
        ?int $transactionSuperTypeId,
        ?int $transactionTypeId,
        array $accountIds,
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

        if ($accountIds !== []) {
            $labels['الحساب'] = Account::withTrashed()
                ->whereIn('id', $accountIds)
                ->orderBy('account_code')
                ->orderBy('name')
                ->get(['account_code', 'name'])
                ->map(fn ($account) => trim(($account->account_code ? $account->account_code . ' - ' : '') . $account->name))
                ->implode('، ');
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
