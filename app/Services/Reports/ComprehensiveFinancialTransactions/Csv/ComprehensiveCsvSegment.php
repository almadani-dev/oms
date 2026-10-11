<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Csv;

use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvPart;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjection;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportProjectionQuery;
use App\Services\Reports\ComprehensiveFinancialTransactions\Projection\ComprehensiveReportResolvedFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Writes the movement CSV records of ONE time segment of an export, in the
 * report's canonical order (transaction_time, transaction id, line id).
 *
 * The segment is read in contiguous windows [from, before) (whole months,
 * else days — planned by ComprehensiveCsvExport). Per window, all in the
 * caller's one consistent snapshot:
 *  1. the distinct transaction ids of the window's REPORT lines (the
 *     projection with every report filter — ComprehensiveReportProjectionQuery::lines(),
 *     the screen's own filter builder);
 *  2. those transactions' own fields and texts, and their live source
 *     records' notes (the five source tables, in the report's table order,
 *     each by record id), read ONCE per transaction by id ranges — primary
 *     key of `transactions`, transaction_id index of each source table. Ids
 *     close together are read as ranges (TRANSACTION_ID_GAP / NOTE_ID_GAP),
 *     the rest as id lists — never a scan of the window's other transactions
 *     (a sparse export — an account, a project — is point reads);
 *  3. ONE streamed query (unbuffered on MySQL — rows are never held in
 *     memory): the window's report lines LEFT JOINed to their own ledger line.
 *
 * Measured on oms_stress (TASKS_LOG 2026-10-10): joining `transactions` per
 * line re-read every description 2–4 times (one per line) and the source
 * notes joined by time cost more than all the lines; reading each transaction
 * and note once, by id, removed both. Memory is bounded by ONE window's
 * transactions (window_max_rows), never by the segment or the export.
 *
 * Values are the screen's: rendered from the LEDGER line and transaction,
 * exactly as ComprehensiveFinancialTransactionsReportService::rowsForLines()
 * renders a projected page; every master-data label from
 * ComprehensiveCsvLabels. Every row is also checked against its projection
 * row (ledger line and transaction present and not deleted, same account /
 * currency / cost item / type / time / amounts): a difference is projection
 * drift and aborts the export — never a guess.
 *
 * Returns, per transaction type × currency, the line count, debit and credit
 * (cents) and a CRC-32 sum over every row's identity and amounts, plus the
 * distinct transactions per type — the fingerprint fingerprint() reproduces
 * from the projection alone, so the export can prove what was written.
 */
final class ComprehensiveCsvSegment
{
    private const FLUSH_ROWS = 1000;

    /**
     * Ids at most this far apart are read as one range (the ids in between
     * are read and ignored), separately for the wide `transactions` rows and
     * for the source tables' transaction_id indexes (which hold only their
     * own records, so a range costs little there). Transaction ids are not in
     * time order, so a window's ids interleave with other windows'. Measured
     * on oms_stress (TASKS_LOG 2026-10-10).
     */
    private const TRANSACTION_ID_GAP = 4;

    private const NOTE_ID_GAP = 64;

    /** Ranges / ids per id-range query. */
    private const RANGES_PER_QUERY = 500;

    /** Report table order of the source-record notes (ComprehensiveFinancialTransactionsReportService::SOURCE_NOTE_TABLES). */
    private const SOURCE_NOTE_TABLES = [
        ['general_expenses', 'ملاحظات المصروف العام'],
        ['general_exchanges', 'ملاحظات مبلغ الصرف'],
        ['project_cost_receipts', 'ملاحظات المبلغ المستلم'],
        ['project_cost_budgets_payments', 'ملاحظات دفعة الصرف'],
        ['project_cost_budgets', 'ملاحظات موازنة التكلفة'],
    ];

    public function __construct(private readonly ComprehensiveReportProjectionQuery $query) {}

    /**
     * @param  array<int, array{0: string, 1: string}>  $windows  contiguous [from, before) datetimes, in order
     * @return array{rows: int, groups: array<string, array{0: int, 1: int, 2: int, 3: int}>, transactions: array<int, int>, seconds: array<string, float>}
     */
    public function write(ComprehensiveReportResolvedFilters $filters, ComprehensiveCsvLabels $labels, array $windows, AccountStatementCsvPart $out): array
    {
        $result = ['rows' => 0, 'groups' => [], 'transactions' => [], 'seconds' => ['ids' => 0.0, 'transactions' => 0.0, 'notes' => 0.0, 'stream' => 0.0]];

        if ($filters->matchesNothing()) {
            return $result;
        }

        foreach ($windows as [$from, $before]) {
            if ($from >= $before) {
                continue;
            }

            $t = microtime(true);
            $ids = $this->transactionIds($filters, $from, $before);
            $result['seconds']['ids'] += microtime(true) - $t;

            if ($ids === []) {
                continue;
            }

            $t = microtime(true);
            $transactions = $this->transactionsById(self::ranges($ids, (int) config(ComprehensiveCsvExport::CONFIG.'.transaction_id_gap', self::TRANSACTION_ID_GAP)));
            $result['seconds']['transactions'] += microtime(true) - $t;

            $t = microtime(true);
            $notes = $this->sourceNotes(self::ranges($ids, (int) config(ComprehensiveCsvExport::CONFIG.'.note_id_gap', self::NOTE_ID_GAP)));
            $result['seconds']['notes'] += microtime(true) - $t;

            $t = microtime(true);
            $this->stream($filters, $labels, $from, $before, $transactions, $notes, $out, $result);
            $result['seconds']['stream'] += microtime(true) - $t;
            unset($transactions, $notes);
        }

        $result['seconds'] = array_map(fn (float $s): float => round($s, 3), $result['seconds']);

        return $result;
    }

    /**
     * The same groups, sums, CRC and per-type transactions as write()
     * returns, read again straight from the projection (no ledger, no text)
     * in the CALLER's snapshot — the verification read of a segment.
     *
     * Streamed (unbuffered on MySQL) and computed here, byte for byte as in
     * stream(): measured ~6× faster than the same aggregate in SQL
     * (CONCAT_WS / CRC32 / COUNT(DISTINCT) … WITH ROLLUP sorts the segment).
     *
     * @return array{rows: int, groups: array<string, array{0: int, 1: int, 2: int, 3: int}>, transactions: array<int, int>}
     */
    public function fingerprint(ComprehensiveReportResolvedFilters $filters, string $from, string $before): array
    {
        $result = ['rows' => 0, 'groups' => [], 'transactions' => []];

        if ($filters->matchesNothing() || $from >= $before) {
            return $result;
        }

        $query = ComprehensiveReportProjectionQuery::orderCanonically(
            self::pinIndex($this->query->lines($filters, $from, self::lastSecond($before)), $filters)
                ->select(['transaction_time', 'transaction_id', 'line_id', 'transaction_type_id', 'account_id', 'currency_id', 'project_cost_id', 'debit', 'credit']),
        );

        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $mysql = $connection->getDriverName() === 'mysql';
        $buffered = $mysql ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;
        $groups = [];
        $transactions = [];
        $rows = 0;
        $lastTransaction = null;

        if ($mysql) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $statement = $pdo->prepare($query->toSql());

            foreach ($query->getBindings() as $i => $value) {
                $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $statement->execute();

            while ($r = $statement->fetch(PDO::FETCH_NUM)) {
                $time = $r[0];
                if (! is_string($time) || strlen($time) !== 19) {
                    $time = ComprehensiveReportProjection::time($time);
                }

                $debit = $r[7];
                if (! is_string($debit) || ! isset($debit[3]) || $debit[-3] !== '.') {
                    $debit = ComprehensiveReportProjection::money($debit);
                }

                $credit = $r[8];
                if (! is_string($credit) || ! isset($credit[3]) || $credit[-3] !== '.') {
                    $credit = ComprehensiveReportProjection::money($credit);
                }

                $transactionId = (int) $r[1];
                $typeId = (int) $r[3];
                $costId = $r[6] === null ? null : (int) $r[6];
                $key = $typeId.'|'.$r[5];
                $crc = crc32($time.'|'.$transactionId.'|'.$r[2].'|'.((int) $r[4]).'|'.$costId.'|'.$debit.'|'.$credit);

                if (isset($groups[$key])) {
                    $groups[$key][0]++;
                    $groups[$key][1] += (int) str_replace('.', '', $debit);
                    $groups[$key][2] += (int) str_replace('.', '', $credit);
                    $groups[$key][3] += $crc;
                } else {
                    $groups[$key] = [1, (int) str_replace('.', '', $debit), (int) str_replace('.', '', $credit), $crc];
                }

                if ($transactionId !== $lastTransaction) {
                    $lastTransaction = $transactionId;
                    $transactions[$typeId] = ($transactions[$typeId] ?? 0) + 1;
                }

                $rows++;
            }

            $statement->closeCursor();
        } finally {
            if ($mysql) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }

        return ['rows' => $rows, 'groups' => $groups, 'transactions' => $transactions];
    }

    /**
     * With an account selection, the account index — (account, time,
     * transaction, line, …) — reads exactly the selected accounts' lines of
     * the window; the optimizer otherwise prefers walking the whole window
     * in primary-key order to skip a sort (measured on oms_stress: one
     * account, full range = a 1.2M-row walk for ~28k lines). The sort is of
     * one window's lines only. Not with a project filter too: the cost-item
     * index is then the selective one, and the optimizer picks it (account +
     * project, 42 lines: 5.4 s pinned to the account index).
     */
    private static function pinIndex(Builder $lines, ComprehensiveReportResolvedFilters $filters): Builder
    {
        return $filters->accountIds === [] || $filters->projectCostIds !== null ? $lines : $lines->forceIndex('crl_account_index');
    }

    /**
     * Inclusive end of [.., $before): DATETIME has whole seconds.
     */
    public static function lastSecond(string $before): string
    {
        return Carbon::parse($before)->subSecond()->format('Y-m-d H:i:s');
    }

    /**
     * The resolved filters as plain data, for a segment worker.
     *
     * @return array<string, mixed>
     */
    public static function filtersToArray(ComprehensiveReportResolvedFilters $f): array
    {
        return [
            'from' => $f->from,
            'to' => $f->to,
            'type_ids' => $f->typeIds,
            'currency_ids' => $f->currencyIds,
            'account_ids' => $f->accountIds,
            'account_side' => $f->accountSide,
            'account_type_id' => $f->accountTypeId,
            'project_cost_ids' => $f->projectCostIds,
            'excluded_account_ids' => $f->excludedAccountIds,
            'excluded_type_ids' => $f->excludedTypeIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     */
    public static function filtersFromArray(array $a): ComprehensiveReportResolvedFilters
    {
        $ints = fn (?array $ids): ?array => $ids === null ? null : array_values(array_map('intval', $ids));

        return new ComprehensiveReportResolvedFilters(
            (string) $a['from'],
            (string) $a['to'],
            $ints($a['type_ids']),
            $ints($a['currency_ids']),
            $ints($a['account_ids']),
            (string) $a['account_side'],
            $a['account_type_id'] === null ? null : (int) $a['account_type_id'],
            $ints($a['project_cost_ids']),
            $ints($a['excluded_account_ids']),
            $ints($a['excluded_type_ids'] ?? []),
        );
    }

    // ---------------------------------------------------------------------

    /**
     * @return array<int, int> ascending
     */
    private function transactionIds(ComprehensiveReportResolvedFilters $filters, string $from, string $before): array
    {
        return self::pinIndex($this->query->lines($filters, $from, self::lastSecond($before)), $filters)
            ->distinct()
            ->orderBy('transaction_id')
            ->pluck('transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Ascending ids as [first, last] ranges, joining ids at most $gap apart.
     *
     * @param  array<int, int>  $ids  ascending, distinct
     * @return array<int, array{0: int, 1: int}>
     */
    public static function ranges(array $ids, int $gap = 1): array
    {
        $ranges = [];
        $gap = max(1, $gap);

        foreach ($ids as $id) {
            if ($ranges !== [] && $id - $ranges[array_key_last($ranges)][1] <= $gap) {
                $ranges[array_key_last($ranges)][1] = $id;
            } else {
                $ranges[] = [$id, $id];
            }
        }

        return $ranges;
    }

    /**
     * Adds "column within these ranges" (single ids as one IN list).
     *
     * @param  array<int, array{0: int, 1: int}>  $ranges
     */
    private static function whereInRanges(Builder $query, string $column, array $ranges): Builder
    {
        return $query->where(function (Builder $q) use ($column, $ranges): void {
            $singles = [];

            foreach ($ranges as [$first, $last]) {
                $first === $last ? $singles[] = $first : $q->orWhereBetween($column, [$first, $last]);
            }

            if ($singles !== []) {
                $q->orWhereIn($column, $singles);
            }
        });
    }

    /**
     * transaction id => [deleted_at, type id, time, number, description, notes, created_by]
     *
     * @param  array<int, array{0: int, 1: int}>  $ranges
     * @return array<int, array<int, mixed>>
     */
    private function transactionsById(array $ranges): array
    {
        $map = [];

        foreach (array_chunk($ranges, self::RANGES_PER_QUERY) as $chunk) {
            $query = self::whereInRanges(DB::table('transactions'), 'id', $chunk)
                ->select(['id', 'deleted_at', 'transaction_type_id', 'transaction_time', 'transaction_number', 'description', 'notes', 'created_by']);
            $statement = DB::connection()->getPdo()->prepare($query->toSql());
            $statement->execute($query->getBindings());

            while ($r = $statement->fetch(PDO::FETCH_NUM)) {
                $map[(int) $r[0]] = $r;
            }
        }

        return $map;
    }

    /**
     * transaction id => already "label: text" lines of its live source
     * records, in the report's order (table order, then record id).
     *
     * @param  array<int, array{0: int, 1: int}>  $ranges
     * @return array<int, string>
     */
    private function sourceNotes(array $ranges): array
    {
        $map = [];

        foreach (self::SOURCE_NOTE_TABLES as [$table, $label]) {
            foreach (array_chunk($ranges, self::RANGES_PER_QUERY) as $chunk) {
                $records = self::whereInRanges(DB::table($table), 'transaction_id', $chunk)
                    ->whereNull('deleted_at')
                    ->whereNotNull('notes')
                    ->orderBy('transaction_id')
                    ->orderBy('id')
                    ->get(['transaction_id', 'notes']);

                foreach ($records as $record) {
                    $text = trim((string) $record->notes);

                    if ($text !== '') {
                        $id = (int) $record->transaction_id;
                        $map[$id] = (isset($map[$id]) ? $map[$id]."\n" : '').$label.': '.$text;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array<int, mixed>>  $transactions
     * @param  array<int, string>  $sourceNotes
     * @param  array<string, mixed>  $result
     */
    private function stream(ComprehensiveReportResolvedFilters $filters, ComprehensiveCsvLabels $labels, string $from, string $before, array $transactions, array $sourceNotes, AccountStatementCsvPart $out, array &$result): void
    {
        [$sql, $bindings] = $this->sql($filters, $from, $before);

        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $mysql = $connection->getDriverName() === 'mysql';
        $buffered = $mysql ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;

        // Hot-loop locals.
        $types = $labels->types;
        $currencies = $labels->currencies;
        $accounts = $labels->accounts;
        $costs = $labels->costs;
        $users = $labels->users;
        $roles = $labels->roles;
        $text = AccountStatementCsvFormat::text(...);
        $unspecified = $text(ComprehensiveCsvFormat::UNSPECIFIED);
        $noType = [$unspecified, $unspecified];
        $noProject = $text(ComprehensiveCsvFormat::NO_PROJECT);
        // The report's own placeholder: a lone '-' is not a formula, so it
        // is written as is (a user NAMED "-..." is still guarded).
        $noUser = ComprehensiveCsvFormat::NO_USER;
        $dash = ComprehensiveCsvFormat::DASH;

        $groups = $result['groups'];
        $transactionCounts = $result['transactions'];
        $rows = $result['rows'];
        $lastTransaction = null;
        $tx = null;
        $txCells = '';
        $txNotes = '';
        $txUser = '';
        $txSourceNotes = '';
        $buffer = '';

        if ($mysql) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $statement = $pdo->prepare($sql);

            foreach ($bindings as $i => $value) {
                $statement->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            $statement->execute();

            // 0 time, 1 transaction id, 2 line id, 3 type, 4 account, 5 currency,
            // 6 cost item, 7 debit, 8 credit (projection) | 9 tl.id,
            // 10 tl.deleted_at, 11 tl.account_id, 12 tl.currency_id,
            // 13 tl.project_cost_id, 14 tl.debit_base, 15 tl.credit_base,
            // 16 tl.notes, 17 tl.line_role, 18 tl.description
            // Transaction (from $transactions): 0 id, 1 deleted_at, 2 type,
            // 3 time, 4 number, 5 description, 6 notes, 7 created_by
            while ($r = $statement->fetch(PDO::FETCH_NUM)) {
                $time = $r[0];
                if (! is_string($time) || strlen($time) !== 19) {
                    $time = ComprehensiveReportProjection::time($time);
                }

                $debit = $r[7];
                if (! is_string($debit) || ! isset($debit[3]) || $debit[-3] !== '.') {
                    $debit = ComprehensiveReportProjection::money($debit);
                }

                $credit = $r[8];
                if (! is_string($credit) || ! isset($credit[3]) || $credit[-3] !== '.') {
                    $credit = ComprehensiveReportProjection::money($credit);
                }

                $transactionId = (int) $r[1];
                $typeId = (int) $r[3];

                if ($transactionId !== $lastTransaction) {
                    // A transaction's lines are consecutive in canonical
                    // order: its own cells are built once.
                    $lastTransaction = $transactionId;
                    $transactionCounts[$typeId] = ($transactionCounts[$typeId] ?? 0) + 1;
                    $tx = $transactions[$transactionId] ?? null;

                    if ($tx === null || $tx[1] !== null || $tx[2] != $r[3] || ($tx[3] !== $time && ComprehensiveReportProjection::time($tx[3]) !== $time)) {
                        throw new ComprehensiveCsvDrift("The report projection disagrees with the ledger at transaction {$transactionId}; run oms:verify-comprehensive-report-projection.");
                    }

                    $type = $types[$typeId] ?? $noType;
                    $description = trim((string) $tx[5]);
                    $txCells = ','.($tx[4] ? $text((string) $tx[4]) : 'قيد #'.$transactionId)
                        .','.$type[1]
                        .','.$type[0];
                    $txUser = ','.($tx[7] === null ? $noUser : ($users[(int) $tx[7]] ?? $noUser))
                        .','.($description === '' ? $dash : $text($description));
                    $note = trim((string) $tx[6]);
                    $txNotes = $note === '' ? '' : 'ملاحظات المعاملة: '.$note;
                    $txSourceNotes = $sourceNotes[$transactionId] ?? '';
                }

                // The ledger line must say exactly what the projection says
                // (loose comparisons: driver-native ints / decimal strings).
                if ($r[9] === null || $r[10] !== null || $r[11] != $r[4] || $r[12] != $r[5] || $r[13] != $r[6] || $r[14] != $debit || $r[15] != $credit) {
                    throw new ComprehensiveCsvDrift("The report projection disagrees with the ledger at line {$r[2]}; run oms:verify-comprehensive-report-projection.");
                }

                $accountId = (int) $r[4];
                $costId = $r[6] === null ? null : (int) $r[6];
                $key = $typeId.'|'.$r[5];
                $crc = crc32($time.'|'.$transactionId.'|'.$r[2].'|'.$accountId.'|'.$costId.'|'.$debit.'|'.$credit);
                $debitCents = (int) str_replace('.', '', $debit);
                $creditCents = (int) str_replace('.', '', $credit);

                if (isset($groups[$key])) {
                    $groups[$key][0]++;
                    $groups[$key][1] += $debitCents;
                    $groups[$key][2] += $creditCents;
                    $groups[$key][3] += $crc;
                } else {
                    $groups[$key] = [1, $debitCents, $creditCents, $crc];
                }

                $account = $accounts[$accountId] ?? null;

                if ($account === null) {
                    throw new ComprehensiveCsvDrift("Line {$r[2]} points to a missing account.");
                }

                $cost = $costId === null ? null : ($costs[$costId] ?? null);

                // Notes: transaction, line, account, cost item, then the
                // transaction's source records — the report's own order.
                $notes = $txNotes;
                $note = trim((string) $r[16]);
                if ($note !== '') {
                    $notes .= ($notes === '' ? '' : "\n").'ملاحظات سطر القيد: '.$note;
                }
                if ($account[3] !== '') {
                    $notes .= ($notes === '' ? '' : "\n").'ملاحظات الحساب: '.$account[3];
                }
                if ($cost !== null && $cost[1] !== '') {
                    $notes .= ($notes === '' ? '' : "\n").'ملاحظات بند التكلفة: '.$cost[1];
                }
                if ($txSourceNotes !== '') {
                    $notes .= ($notes === '' ? '' : "\n").$txSourceNotes;
                }

                $lineDescription = trim((string) $r[18]);

                $buffer .= substr($time, 0, 10)
                    .$txCells
                    .','.$account[0]
                    .','.$account[1]
                    .','.$account[2]
                    .','.($cost === null ? $noProject : $cost[0])
                    .','.($currencies[(int) $r[5]] ?? $unspecified)
                    .','.$debit
                    .','.$credit
                    .$txUser
                    .','.($r[17] === null ? $dash : ($roles[$r[17]] ?? $dash))
                    .','.($lineDescription === '' ? $dash : $text($lineDescription))
                    .','.($notes === '' ? '' : $text($notes))
                    ."\r\n";

                if (++$rows % self::FLUSH_ROWS === 0) {
                    $out->write($buffer);
                    $buffer = '';
                }
            }

            $statement->closeCursor();
        } finally {
            if ($mysql) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }

        $out->write($buffer);

        $result['groups'] = $groups;
        $result['transactions'] = $transactionCounts;
        $result['rows'] = $rows;
    }

    /**
     * The window's report lines (the screen's filter builder, as a derived
     * table so its unqualified columns stay unambiguous) with their own
     * ledger line, in canonical order.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function sql(ComprehensiveReportResolvedFilters $filters, string $from, string $before): array
    {
        $lines = self::pinIndex($this->query->lines($filters, $from, self::lastSecond($before)), $filters)
            ->select(['transaction_time', 'transaction_id', 'line_id', 'transaction_type_id', 'account_id', 'currency_id', 'project_cost_id', 'debit', 'credit']);

        $query = DB::query()->fromSub($lines, 'l')
            ->leftJoin('transaction_lines as tl', 'tl.id', '=', 'l.line_id')
            ->select([
                'l.transaction_time', 'l.transaction_id', 'l.line_id', 'l.transaction_type_id', 'l.account_id', 'l.currency_id', 'l.project_cost_id', 'l.debit', 'l.credit',
                'tl.id as tl_id', 'tl.deleted_at as tl_deleted_at', 'tl.account_id as tl_account_id', 'tl.currency_id as tl_currency_id', 'tl.project_cost_id as tl_project_cost_id',
                'tl.debit_base', 'tl.credit_base', 'tl.notes as tl_notes', 'tl.line_role', 'tl.description as tl_description',
            ])
            ->orderBy('l.transaction_time')
            ->orderBy('l.transaction_id')
            ->orderBy('l.line_id');

        return [$query->toSql(), $query->getBindings()];
    }
}
