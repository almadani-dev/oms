<?php

namespace App\Services\Reports;

use App\Enums\TransactionLineRole;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Currency;
use App\Models\Project;
use App\Models\TransactionSuperType;
use App\Models\TransactionType;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsFilters;
use App\Services\Reports\ComprehensiveFinancialTransactions\ComprehensiveFinancialTransactionsTotals;
use Illuminate\Database\Query\Builder;
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
    /**
     * Financial source records that point AT a transaction via their own
     * nullable transaction_id FK. There is no polymorphic source pointer on
     * transactions, and two of these (receipts, budget payments) are
     * one-to-many — joining them into the main lines query would fan the
     * result out and duplicate debit/credit lines, corrupting every summary.
     *
     * They are therefore pre-fetched separately, per keyset batch, in exactly
     * one bounded whereIn() query per table (over at most one batch of
     * transaction ids), and merged in PHP. See sourceNotesByTransaction().
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
     * Side of the selected accounts ("طرف الحساب"). Only ever narrows the
     * rows of an EXISTING account selection — see normalizeAccountSide().
     */
    public const SIDE_ALL = 'all';

    public const SIDE_DEBIT = 'debit';

    public const SIDE_CREDIT = 'credit';

    /**
     * @var array<string, string> Arabic label per account side.
     */
    private const SIDE_LABELS = [
        self::SIDE_ALL => 'الكل',
        self::SIDE_DEBIT => 'مدين',
        self::SIDE_CREDIT => 'دائن',
    ];

    /**
     * Whole-report convenience API, kept for direct callers and the existing
     * service-level tests. It returns exactly the shape it always did, but it
     * is now built from the same bounded keyset batches the snapshot builder
     * streams (see batches()), so it can never disagree with the page.
     *
     * It still returns every row in one array by contract — the page and the
     * exports therefore never call it; they read an immutable snapshot built
     * by ComprehensiveFinancialTransactionsSnapshotBuilder instead.
     *
     * @param array<int, int> $currencyIds empty array = all currencies
     * @param array<int, int> $accountIds empty array = all accounts ("كل الحسابات")
     * @param string $accountSide self::SIDE_* — narrows the selected accounts to
     *        their debit or credit appearances; meaningless (and forced back to
     *        SIDE_ALL) while $accountIds is empty.
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
        string $accountSide = self::SIDE_ALL,
    ): array {
        $filters = ComprehensiveFinancialTransactionsFilters::make(
            $dateFrom,
            $dateTo,
            $currencyIds,
            $transactionSuperTypeId,
            $transactionTypeId,
            $accountIds,
            $accountTypeId,
            $projectId,
            $accountSide,
        );

        $totals = new ComprehensiveFinancialTransactionsTotals();
        $rows = [];

        foreach ($this->batches($filters, $totals) as $batch) {
            array_push($rows, ...$batch);
        }

        return ['rows' => $rows] + $totals->result() + [
            'filter_labels' => $this->filterLabels($filters),
            // The normalized side actually applied to the query — the page
            // stores THIS as its applied snapshot, never the live form
            // value, so the export audit always describes the exported rows.
            'account_side' => $filters->accountSide,
        ];
    }

    /**
     * Streams the report's rows in canonical order — transaction_time ASC,
     * transaction id ASC, transaction_line id ASC — one bounded batch at a
     * time, feeding every line into $totals before its batch is yielded.
     *
     * Two-level keyset, never OFFSET and never chunkById() (which would
     * re-order by line id alone):
     *
     *  1. the next batch_size TRANSACTIONS strictly after the last
     *     (transaction_time, id) seen. That order lives on ONE table and is
     *     exactly the transactions(transaction_time) index (InnoDB appends the
     *     primary key), so the database walks the index and stops at LIMIT;
     *  2. those transactions' LINES (through the transaction_lines
     *     (transaction_id) FK index), strictly after the last
     *     (transaction_time, transaction id, line id) seen, LIMIT batch_size.
     *     Only lines of at most batch_size transactions are ever sorted.
     *
     * A single keyset over the line tuple would be simpler, but its ORDER BY
     * spans two tables, so no index can supply it: every batch would sort all
     * remaining lines of the period again — measured, that grows
     * quadratically with the report. Here every query's work is bounded by
     * one batch, whatever the size of the period.
     *
     * Both cursors are the ORDER BY tuples and both are unique, so no line
     * can be skipped or repeated at a boundary — including when many
     * transactions share one transaction_time, and when one transaction has
     * more lines than a batch.
     *
     * Source-record notes are attached per yielded batch (at most one bounded
     * whereIn() per source table, over at most batch_size transaction ids),
     * AFTER the batch's lines were added to $totals — so, as before, no note
     * can ever affect a summary. A transaction split across two batches has
     * its source notes looked up once for each half; the result is identical.
     *
     * $onTransactionPage, when given, is called after each page of
     * transactions has been fully yielded, with the number of transactions
     * that page covered — the builder's honest progress unit.
     *
     * @param  (callable(int): void)|null  $onTransactionPage
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function batches(ComprehensiveFinancialTransactionsFilters $filters, ComprehensiveFinancialTransactionsTotals $totals, ?int $batchSize = null, ?callable $onTransactionPage = null): \Generator
    {
        $batchSize = max(1, $batchSize ?? (int) config('oms.reports.comprehensive_financial_transactions.batch_size', 1000));
        $typeIdsOfSuperType = $this->typeIdsOfSuperType($filters);
        $transactionCursor = null;

        do {
            $transactions = $this->transactionsQuery($filters, $typeIdsOfSuperType)
                ->when($transactionCursor !== null, function ($q) use ($transactionCursor) {
                    [$time, $transactionId] = $transactionCursor;

                    $q->where('t.transaction_time', '>=', $time)
                        ->where(function ($q) use ($time, $transactionId) {
                            $q->where('t.transaction_time', '>', $time)
                                ->orWhere(function ($q) use ($time, $transactionId) {
                                    $q->where('t.transaction_time', '=', $time)
                                        ->where('t.id', '>', $transactionId);
                                });
                        });
                })
                ->orderBy('t.transaction_time')
                ->orderBy('t.id')
                ->limit($batchSize)
                ->get(['t.id', 't.transaction_time']);

            if ($transactions->isEmpty()) {
                return;
            }

            $last = $transactions->last();
            $transactionCursor = [(string) $last->transaction_time, (int) $last->id];
            $pageTransactions = $transactions->count();
            $moreTransactions = $pageTransactions === $batchSize;
            $transactionIds = $transactions->pluck('id')->map(fn ($id) => (int) $id)->all();
            unset($transactions, $last);

            $lineCursor = null;

            do {
                $lines = $this->linesQuery($filters)
                    // On the line's own FK column, so the slice is read
                    // through transaction_lines(transaction_id).
                    ->whereIn('tl.transaction_id', $transactionIds)
                    ->when($lineCursor !== null, function ($q) use ($lineCursor) {
                        [$time, $transactionId, $lineId] = $lineCursor;

                        $q->where(function ($q) use ($time, $transactionId, $lineId) {
                            $q->where('t.transaction_time', '>', $time)
                                ->orWhere(function ($q) use ($time, $transactionId, $lineId) {
                                    $q->where('t.transaction_time', '=', $time)
                                        ->where(function ($q) use ($transactionId, $lineId) {
                                            $q->where('t.id', '>', $transactionId)
                                                ->orWhere(function ($q) use ($transactionId, $lineId) {
                                                    $q->where('t.id', '=', $transactionId)
                                                        ->where('tl.id', '>', $lineId);
                                                });
                                        });
                                });
                        });
                    })
                    ->limit($batchSize)
                    ->get();

                if ($lines->isEmpty()) {
                    break;
                }

                $rows = [];
                $batchTransactionIds = [];

                foreach ($lines as $line) {
                    $batchTransactionIds[$line->transaction_id] = true;
                    $rows[] = $this->row($line, $totals);
                }

                $last = $lines->last();
                $lineCursor = [(string) $last->transaction_time, (int) $last->transaction_id, (int) $last->line_id];
                $moreLines = $lines->count() === $batchSize;
                unset($lines, $last);

                // Deliberately AFTER the batch was accumulated, so no summary,
                // total or currency bucket can be affected by a note.
                $this->attachSourceNotes($rows, array_map('intval', array_keys($batchTransactionIds)));

                yield $rows;
            } while ($moreLines);

            if ($onTransactionPage !== null) {
                $onTransactionPage($pageTransactions);
            }
        } while ($moreTransactions);
    }

    /**
     * Exact number of transactions batches() will page through — the same
     * single-table, index-range query without LIMIT, so it costs one index
     * range count, never a scan of transaction_lines. The denominator of the
     * background build's progress.
     */
    public function candidateTransactionCount(ComprehensiveFinancialTransactionsFilters $filters): int
    {
        return $this->transactionsQuery($filters, $this->typeIdsOfSuperType($filters))->count();
    }

    /**
     * One report row from one lines-query record, and that line's
     * contribution to every summary. Unchanged from the original
     * report-wide loop, line for line.
     *
     * @return array<string, mixed>
     */
    private function row(object $line, ComprehensiveFinancialTransactionsTotals $totals): array
    {
        $debit = (float) $line->debit_base;
        $credit = (float) $line->credit_base;

        $currencyLabel = $this->currencyLabel($line->currency_name, $line->currency_code);
        $currencyShort = $line->currency_code ?: ($line->currency_name ?: 'غير محدد');
        $category = $line->super_type_name ?: 'غير محدد';
        $type = $line->type_name ?: 'غير محدد';

        $totals->add(
            (string) $line->transaction_time,
            (int) $line->transaction_id,
            (int) $line->line_id,
            $currencyLabel,
            $currencyShort,
            $category,
            $this->groupKey($line->super_type_id),
            $type,
            $this->groupKey($line->type_id),
            $debit,
            $credit,
        );

        return [
            'transaction_id' => (int) $line->transaction_id,
            'line_id' => (int) $line->line_id,
            'date' => self::lineDate($line->transaction_time),
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
            // Source-record notes are appended by attachSourceNotes(); every
            // entry keeps its own source label so a note's origin is never
            // hidden, and identical text from two different records stays as
            // two entries.
            'notes' => $this->ownNotes($line),
        ];
    }

    /**
     * The candidate transactions of the period, in canonical order: a
     * deliberately SINGLE-TABLE query, so the only plan the database can
     * reasonably choose is a range walk of transactions(transaction_time)
     * that stops at the LIMIT — no join or subquery an optimizer could
     * rewrite into a whole-period sort.
     *
     * Only transaction-level filters apply here. It may therefore return
     * transactions with no reportable line (wrong currency/account, all lines
     * deleted, …); the lines query applies every filter and such a
     * transaction simply contributes nothing. It can never drop a line.
     *
     * @param  array<int, int>|null  $typeIdsOfSuperType  the classification filter, resolved once per run
     */
    private function transactionsQuery(ComprehensiveFinancialTransactionsFilters $filters, ?array $typeIdsOfSuperType): Builder
    {
        $transactionTypeId = $filters->transactionTypeId;

        return DB::table('transactions as t')
            ->whereNull('t.deleted_at')
            ->whereBetween('t.transaction_time', $this->period($filters))
            ->when($transactionTypeId, fn ($q) => $q->where('t.transaction_type_id', $transactionTypeId))
            ->when($typeIdsOfSuperType !== null, fn ($q) => $q->whereIn('t.transaction_type_id', $typeIdsOfSuperType));
    }

    /**
     * The transaction types of the filtered classification — the same rows
     * the lines query's unguarded LEFT JOIN on transactions_types matches
     * (soft-deleted types included). Null when no classification filter.
     *
     * @return array<int, int>|null
     */
    private function typeIdsOfSuperType(ComprehensiveFinancialTransactionsFilters $filters): ?array
    {
        if (! $filters->transactionSuperTypeId) {
            return null;
        }

        return DB::table('transactions_types')
            ->where('transaction_super_type_id', $filters->transactionSuperTypeId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Line-level filters of the lines query.
     */
    private function applyLineFilters(Builder $q, ComprehensiveFinancialTransactionsFilters $filters): void
    {
        $currencyIds = $filters->currencyIds;
        $accountIds = $filters->accountIds;
        $accountSide = $filters->accountSide;
        $accountTypeId = $filters->accountTypeId;
        $projectId = $filters->projectId;

        $q->when($currencyIds !== [], fn ($q) => $q->whereIn('tl.currency_id', $currencyIds))
            ->when($accountIds !== [], fn ($q) => $q->whereIn('tl.account_id', $accountIds))
            // "طرف الحساب": the accounting source of truth is the line's own
            // amount, never the transaction type, line_role or description.
            // A line is a debit appearance only when it actually carries a
            // positive debit_base. Already normalized by the filters object,
            // so these can never fire without an account selection.
            ->when($accountSide === self::SIDE_DEBIT, fn ($q) => $q->where('tl.debit_base', '>', 0))
            ->when($accountSide === self::SIDE_CREDIT, fn ($q) => $q->where('tl.credit_base', '>', 0))
            ->when($accountTypeId, fn ($q) => $q->where('a.account_type_id', $accountTypeId))
            ->when($projectId, fn ($q) => $q->where('pc.project_id', $projectId));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(ComprehensiveFinancialTransactionsFilters $filters): array
    {
        return [
            Carbon::parse($filters->dateFrom)->startOfDay(),
            Carbon::parse($filters->dateTo)->endOfDay(),
        ];
    }

    /**
     * The report's single lines query — joins, SoftDelete guards, filters and
     * canonical ORDER BY — without any limit. batches() adds the transaction
     * set, the keyset cursor and the batch LIMIT on top of it.
     */
    private function linesQuery(ComprehensiveFinancialTransactionsFilters $filters): Builder
    {
        $transactionSuperTypeId = $filters->transactionSuperTypeId;
        $transactionTypeId = $filters->transactionTypeId;

        $query = DB::table('transaction_lines as tl')
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
            ->whereBetween('t.transaction_time', $this->period($filters))
            ->when($transactionSuperTypeId, fn ($q) => $q->where('tt.transaction_super_type_id', $transactionSuperTypeId))
            ->when($transactionTypeId, fn ($q) => $q->where('t.transaction_type_id', $transactionTypeId));

        $this->applyLineFilters($query, $filters);

        return $query
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
            ]);
    }

    /**
     * Single source of truth for what "طرف الحساب" actually means once the
     * rest of the filters are known.
     *
     * The side narrows an account selection; it is not a report-wide
     * debit/credit filter. With no account selected there is nothing to take
     * a side of, so a stale 'debit'/'credit' coming from the UI, an export
     * replay or a direct service call can never silently halve the report —
     * it collapses back to SIDE_ALL here, before the query is built.
     *
     * Static so the page can normalize its own applied snapshot with exactly
     * the same rule the query used.
     *
     * @param array<int, int> $accountIds
     */
    public static function normalizeAccountSide(?string $accountSide, array $accountIds): string
    {
        if ($accountIds === []) {
            return self::SIDE_ALL;
        }

        return in_array($accountSide, [self::SIDE_DEBIT, self::SIDE_CREDIT], true)
            ? $accountSide
            : self::SIDE_ALL;
    }

    /**
     * @return array<string, string> value => Arabic label, for the filter Select.
     */
    public static function accountSideOptions(): array
    {
        return self::SIDE_LABELS;
    }

    public static function accountSideLabel(?string $accountSide): string
    {
        return self::SIDE_LABELS[$accountSide] ?? self::SIDE_LABELS[self::SIDE_ALL];
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
     * Merges the source-record notes of one batch's transactions onto every
     * row of that batch they belong to.
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
     * At most one bounded query per source table (5 per batch, each over at
     * most one batch of transaction ids) — never N+1, never a report-wide id
     * list, and never a join against the main lines query, which would
     * duplicate lines.
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
     * The row's "Y-m-d" date — byte-for-byte what
     * Carbon::parse($time)->format('Y-m-d') returns, without building a Carbon
     * per line (≈ 16 s on a 560k-line report).
     *
     * transactions.transaction_time is a DATETIME, so the database hands back
     * "YYYY-MM-DD HH:MM:SS", which Carbon parses in the application timezone
     * with no conversion; its date is then exactly the first ten characters.
     * The shortcut is taken ONLY for a real calendar date with a real
     * 00:00:00–23:59:59 clock (a value like 25:00 or 02-30 would roll over in
     * Carbon); anything else still goes through Carbon unchanged.
     */
    public static function lineDate(mixed $transactionTime): string
    {
        $value = (string) $transactionTime;

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d)?$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return substr($value, 0, 10);
        }

        return Carbon::parse($transactionTime)->format('Y-m-d');
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
     * above the results, the exports and the export audit.
     *
     * @return array<string, string>
     */
    public function filterLabels(ComprehensiveFinancialTransactionsFilters $filters): array
    {
        return $this->labelsFor(
            $filters->currencyIds,
            $filters->transactionSuperTypeId,
            $filters->transactionTypeId,
            $filters->accountIds,
            $filters->accountTypeId,
            $filters->projectId,
            $filters->accountSide,
        );
    }

    /**
     * @param array<int, int> $currencyIds
     * @param array<int, int> $accountIds
     * @param string $accountSide already normalized by the filters object
     * @return array<string, string>
     */
    private function labelsFor(
        array $currencyIds,
        ?int $transactionSuperTypeId,
        ?int $transactionTypeId,
        array $accountIds,
        ?int $accountTypeId,
        ?int $projectId,
        string $accountSide,
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

        // Listed immediately after الحساب, and only when it actually narrowed
        // something — same convention as every other filter here, which stays
        // out of the summary at its default. Both exporters render the
        // missing key as "الكل" from their own FILTER_KEYS list.
        if ($accountSide !== self::SIDE_ALL) {
            $labels['طرف الحساب'] = self::accountSideLabel($accountSide);
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
