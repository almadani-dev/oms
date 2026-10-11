<?php

namespace App\Services\Reports\AccountStatement\Csv;

use App\Services\Reports\AccountStatement\AccountStatementBalances;
use App\Services\Reports\AccountStatement\AccountStatementProjection;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Writes the movement CSV records of ONE time segment [from, before) of an
 * export (day-aligned), in canonical order (transaction_time, line_id).
 *
 * One streamed query over the projection (unbuffered on MySQL — rows are
 * never held in memory), LEFT JOINed to the ledger for the row's own texts
 * (transaction number and description, line notes, role and description);
 * every other label comes from AccountStatementCsvLabels.
 *
 * Running balance: an integer-cents map account_id => balance, initialised
 * from the checkpoint opening balance at the segment's first day
 * (AccountStatementBalances::prefixByAccount — the screen's own source) and
 * moved by every line of the covered accounts. Balance identity is the
 * ACCOUNT, exactly as on the screen (an account has one currency; lines in
 * another currency are the screen's flagged "mixed currencies" case).
 * Classification / type / project filters only choose the LISTED rows: the
 * other lines still move the balance (their ledger texts are not read).
 *
 * Returns the segment's per-account opening, closing and listed totals, so
 * the export can prove the segments chain exactly and match the summary.
 */
final class AccountStatementCsvSegment
{
    public const SCAN = 'scan';

    public const PRIMARY = 'primary';

    private const FLUSH_ROWS = 1000;

    public function __construct(private readonly AccountStatementBalances $balances) {}

    /**
     * @return array{rows: int, missing_details: int, opening: array<int, int>, closing: array<int, int>, lines: array<int, int>, debit: array<int, int>, credit: array<int, int>, seconds: array{opening: float, stream: float}}
     */
    public function write(AccountStatementScope $scope, AccountStatementCsvLabels $labels, string $path, string $fromDay, string $beforeDay, AccountStatementCsvPart $out): array
    {
        $started = microtime(true);
        $opening = [];

        foreach ($this->balances->prefixByAccount($scope, $fromDay) as $accountId => [, $debit, $credit]) {
            $cents = AccountStatementCsvFormat::centsFromDecimal(bcsub($debit, $credit, 2));

            if ($cents !== 0) {
                $opening[$accountId] = $cents;
            }
        }

        $openedAt = microtime(true);
        $result = $this->stream($scope, $labels, $path, $fromDay, $beforeDay, $out, $opening);
        $result['seconds'] = ['opening' => round($openedAt - $started, 3), 'stream' => round(microtime(true) - $openedAt, 3)];

        return $result;
    }

    /**
     * @param  array<int, int>  $opening
     * @return array<string, mixed>
     */
    private function stream(AccountStatementScope $scope, AccountStatementCsvLabels $labels, string $path, string $fromDay, string $beforeDay, AccountStatementCsvPart $out, array $opening): array
    {
        if ($scope->isEmpty() || $fromDay >= $beforeDay) {
            // Nothing can be listed (no account, or a type / project filter
            // that matches nothing): no row is read, the closing balances
            // come from the checkpoints like the opening ones.
            $closing = [];

            foreach ($scope->accountCount === 0 || $fromDay >= $beforeDay ? [] : $this->balances->prefixByAccount($scope, $beforeDay) as $accountId => [, $debit, $credit]) {
                $cents = AccountStatementCsvFormat::centsFromDecimal(bcsub($debit, $credit, 2));

                if ($cents !== 0) {
                    $closing[$accountId] = $cents;
                }
            }

            return ['rows' => 0, 'missing_details' => 0, 'opening' => $opening, 'closing' => $fromDay >= $beforeDay ? $opening : $closing, 'lines' => [], 'debit' => [], 'credit' => []];
        }

        $typeSet = $scope->typeIds === null ? null : array_flip($scope->typeIds);
        $costSet = $scope->projectCostIds === null ? null : array_flip($scope->projectCostIds);
        [$sql, $bindings] = $this->query($scope, $path, $fromDay, $beforeDay);

        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $mysql = $connection->getDriverName() === 'mysql';
        $buffered = $mysql ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;

        // Hot-loop locals.
        $accounts = $labels->accounts;
        $types = $labels->types;
        $classes = $labels->classes;
        $currencies = $labels->currencies;
        $projects = $labels->projects;
        $roles = $labels->roles;
        $limit = AccountStatementCsvFormat::CENTS_LIMIT;

        $balance = $opening;
        $lines = [];
        $debits = [];
        $credits = [];
        $rows = 0;
        $missing = 0;
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

            while ($r = $statement->fetch(PDO::FETCH_NUM)) {
                $accountId = (int) $r[0];

                $debit = $r[5];
                if (is_string($debit) && isset($debit[3]) && $debit[-3] === '.') {
                    $debitCents = (int) str_replace('.', '', $debit);
                } else {
                    $debitCents = AccountStatementCsvFormat::cents($debit);
                    $debit = AccountStatementCsvFormat::money($debitCents);
                }

                $credit = $r[6];
                if (is_string($credit) && isset($credit[3]) && $credit[-3] === '.') {
                    $creditCents = (int) str_replace('.', '', $credit);
                } else {
                    $creditCents = AccountStatementCsvFormat::cents($credit);
                    $credit = AccountStatementCsvFormat::money($creditCents);
                }

                $running = ($balance[$accountId] ?? 0) + $debitCents - $creditCents;

                if ($running > $limit || $running < -$limit) {
                    throw new \OverflowException('Running balance too large for the CSV export.');
                }

                $balance[$accountId] = $running;

                $typeId = (int) $r[2];
                $costId = $r[3] === null ? null : (int) $r[3];

                // A hidden line only moves the balance.
                if (($typeSet !== null && ! isset($typeSet[$typeId])) || ($costSet !== null && ($costId === null || ! isset($costSet[$costId])))) {
                    continue;
                }

                if ($r[12] === null) {
                    // Projection row without its ledger line (drift): listed
                    // with the screen's empty labels, and reported.
                    $missing++;
                }

                $lines[$accountId] = ($lines[$accountId] ?? 0) + 1;
                $debits[$accountId] = ($debits[$accountId] ?? 0) + $debitCents;
                $credits[$accountId] = ($credits[$accountId] ?? 0) + $creditCents;

                $time = $r[1];
                if (! is_string($time) || strlen($time) !== 19) {
                    $time = AccountStatementProjection::time($time);
                }

                $lineDescription = trim((string) $r[11]);
                $abs = $running < 0 ? -$running : $running;
                $fraction = $abs % 100;

                $buffer .= $time
                    .','.AccountStatementCsvFormat::text($r[7])
                    .','.($types[$typeId] ?? '')
                    .','.($classes[$typeId] ?? '')
                    .','.AccountStatementCsvFormat::text($r[8])
                    .','.AccountStatementCsvFormat::text($r[9])
                    .','.($r[10] === null ? '—' : ($roles[$r[10]] ?? '—'))
                    .','.($lineDescription === '' ? '—' : AccountStatementCsvFormat::text($lineDescription))
                    .','.($costId === null ? '' : ($projects[$costId] ?? ''))
                    .','.($accounts[$accountId] ?? '')
                    .','.($currencies[(int) $r[4]] ?? '')
                    .','.$debit
                    .','.$credit
                    .','.($running < 0 ? '-' : '').intdiv($abs, 100).($fraction < 10 ? '.0' : '.').$fraction
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

        foreach ([$debits, $credits] as $sums) {
            foreach ($sums as $sum) {
                if (! is_int($sum) || $sum > $limit) {
                    throw new \OverflowException('Totals too large for the CSV export.');
                }
            }
        }

        return [
            'rows' => $rows,
            'missing_details' => $missing,
            'opening' => $opening,
            'closing' => array_filter($balance, fn (int $cents): bool => $cents !== 0),
            'lines' => $lines,
            'debit' => $debits,
            'credit' => $credits,
        ];
    }

    /**
     * The segment's rows of every covered account in canonical order, with
     * the ledger texts of the LISTED rows only.
     *
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function query(AccountStatementScope $scope, string $path, string $fromDay, string $beforeDay): array
    {
        [$dimensionSql, $dimensionBindings] = $scope->dimensionCondition('l.');
        $listed = $dimensionSql === '' ? '' : " AND {$dimensionSql}";
        [$accountSql, $accountBindings] = $scope->accountCondition('l.account_id');

        // SCAN walks the global (time, line) index, the account filter
        // evaluated inside it; PRIMARY reads each covered account's clustered
        // range and sorts — cheaper when they hold few of the period's lines.
        $hint = DB::connection()->getDriverName() === 'mysql'
            ? ($path === self::PRIMARY ? ' FORCE INDEX (PRIMARY)' : ' FORCE INDEX (asl_global_order_index)')
            : '';

        $sql = 'SELECT l.account_id, l.transaction_time, l.transaction_type_id, l.project_cost_id, l.currency_id, l.debit, l.credit,'
            .' t.transaction_number, t.description, tl.notes, tl.line_role, tl.description, tl.id'
            .' FROM '.AccountStatementProjection::LINES.' l'.$hint
            ." LEFT JOIN transaction_lines tl ON tl.id = l.line_id{$listed}"
            ." LEFT JOIN transactions t ON t.id = l.transaction_id{$listed}"
            ." WHERE l.transaction_time >= ? AND l.transaction_time < ? AND {$accountSql}"
            .' ORDER BY l.transaction_time, l.line_id';

        return [$sql, [...$dimensionBindings, ...$dimensionBindings, $fromDay.' 00:00:00', $beforeDay.' 00:00:00', ...$accountBindings]];
    }
}
