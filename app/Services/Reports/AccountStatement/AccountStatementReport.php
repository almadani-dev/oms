<?php

namespace App\Services\Reports\AccountStatement;

use App\Enums\TransactionLineRole;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * "تقرير كشف الحساب" over the real-time projection: the one place that
 * turns applied filters into summaries, pages of rows and export rows, so
 * the screen and every export share one implementation.
 *
 * Lifecycle (bounded work per request):
 *  - "عرض": summarize() — per account and per currency, from the checkpoint
 *    totals (O(accounts), never O(lines)) — then the first page;
 *  - First / Next / Previous / Last: page() — one keyset walk of ≤ 50 rows,
 *    their labels in one query, their running balances from the
 *    checkpoints. Summaries are NOT recomputed;
 *  - exports: rows() — the same pages, in chunks, to the end.
 *
 * Balance identity is the ACCOUNT (an account has one currency; lines in a
 * different currency are flagged exactly as the original report flagged
 * them). Several accounts share one ordered table, but every row carries its
 * own account's running balance; money is never summed across accounts of
 * different currencies — totals are per account currency.
 */
final class AccountStatementReport
{
    public const PAGE_SIZE = 50;

    public const ACCOUNT_SUMMARY_PAGE_SIZE = 25;

    public const EXPORT_CHUNK = 1000;

    public function __construct(
        private readonly AccountStatementBalances $balances,
        private readonly AccountStatementRows $finder,
    ) {}

    /**
     * Filter-level summary, computed once per "عرض".
     *
     * @return array{account_count: int, matched_lines: int, selected_lines: int, mixed_currency_accounts: int, plan: string, currencies: array<int, array<string, mixed>>}
     */
    public function summarize(AccountStatementScope $scope): array
    {
        if ($scope->accountCount === 0) {
            return ['account_count' => 0, 'matched_lines' => 0, 'selected_lines' => 0, 'mixed_currency_accounts' => 0, 'plan' => AccountStatementRows::SCAN, 'currencies' => []];
        }

        $opening = $this->balances->prefixByAccount($scope, $scope->fromDay);
        $closing = $this->balances->prefixByAccount($scope, $scope->afterDay);
        $allInPeriod = $this->balances->periodTotalsByAccount(AccountStatementScope::withoutDimensions($scope), null, $opening, $closing);
        $period = $scope->hasDimensionFilters()
            ? ($scope->isEmpty() ? [] : $this->balances->periodTotalsByAccount($scope))
            : $allInPeriod;
        $lineCurrencies = $this->balances->lineCurrenciesByAccount($scope);

        $accountCurrencies = $scope->accountCurrencies;

        $currencies = [];
        $mixed = 0;

        foreach ($accountCurrencies as $accountId => $currencyId) {
            $currencyId = (int) $currencyId;
            $c = $currencies[$currencyId] ??= ['currency_id' => $currencyId, 'accounts' => 0, 'opening' => '0.00', 'debit' => '0.00', 'credit' => '0.00', 'closing' => '0.00', 'lines' => 0];
            $o = $opening[$accountId] ?? [0, '0.00', '0.00'];
            $z = $closing[$accountId] ?? [0, '0.00', '0.00'];
            $p = $period[$accountId] ?? [0, '0.00', '0.00'];

            $c['accounts']++;
            $c['opening'] = bcadd($c['opening'], bcsub($o[1], $o[2], 2), 2);
            $c['closing'] = bcadd($c['closing'], bcsub($z[1], $z[2], 2), 2);
            $c['debit'] = bcadd($c['debit'], $p[1], 2);
            $c['credit'] = bcadd($c['credit'], $p[2], 2);
            $c['lines'] += $p[0];
            $currencies[$currencyId] = $c;

            if (count($lineCurrencies[$accountId] ?? []) > 1) {
                $mixed++;
            }
        }

        $labels = DB::table('currencies')->whereIn('id', array_keys($currencies))->get(['id', 'code', 'name'])->keyBy('id');

        foreach ($currencies as $id => &$c) {
            $c['currency_code'] = $labels[$id]->code ?? null;
            $c['currency_name'] = $labels[$id]->name ?? null;
        }
        unset($c);

        usort($currencies, fn (array $a, array $b): int => strcmp((string) $a['currency_code'], (string) $b['currency_code']) ?: $a['currency_id'] <=> $b['currency_id']);

        $matched = array_sum(array_column($period, 0));
        $selected = array_sum(array_column($allInPeriod, 0));

        return [
            'account_count' => count($accountCurrencies),
            'matched_lines' => $matched,
            'selected_lines' => $selected,
            'mixed_currency_accounts' => $mixed,
            'plan' => $this->finder->plan($scope, $selected, $matched, self::PAGE_SIZE),
            'currencies' => $currencies,
        ];
    }

    /**
     * One page of the per-account summary list (account code order).
     *
     * @return array<int, array<string, mixed>>
     */
    public function accountSummaries(AccountStatementScope $scope, int $page, int $perPage = self::ACCOUNT_SUMMARY_PAGE_SIZE): array
    {
        $accountIds = $scope->accountPage($page, $perPage);

        if ($accountIds === []) {
            return [];
        }

        $opening = $this->balances->prefixByAccount($scope, $scope->fromDay, $accountIds);
        $closing = $this->balances->prefixByAccount($scope, $scope->afterDay, $accountIds);
        $period = $scope->hasDimensionFilters()
            ? ($scope->isEmpty() ? [] : $this->balances->periodTotalsByAccount($scope, $accountIds))
            : $this->balances->periodTotalsByAccount($scope, $accountIds, $opening, $closing);
        $lineCurrencies = $this->balances->lineCurrenciesByAccount($scope, $accountIds);

        $accounts = DB::table('accounts as a')
            ->leftJoin('currencies as c', 'c.id', '=', 'a.currency_id')
            ->whereIn('a.id', $accountIds)
            ->get(['a.id', 'a.account_code', 'a.name', 'a.iban', 'c.code as currency_code'])
            ->keyBy('id');

        $rows = [];

        foreach ($accountIds as $accountId) {
            $account = $accounts[$accountId];
            $o = $opening[$accountId] ?? [0, '0.00', '0.00'];
            $z = $closing[$accountId] ?? [0, '0.00', '0.00'];
            $p = $period[$accountId] ?? [0, '0.00', '0.00'];

            $rows[] = [
                'account_id' => $accountId,
                'account_code' => $account->account_code,
                'account_label' => self::accountLabel($account->account_code, $account->name),
                'currency_code' => $account->currency_code,
                'opening' => bcsub($o[1], $o[2], 2),
                'debit' => $p[1],
                'credit' => $p[2],
                'closing' => bcsub($z[1], $z[2], 2),
                'lines' => $p[0],
                'mixed_currencies' => count($lineCurrencies[$accountId] ?? []) > 1,
            ];
        }

        return $rows;
    }

    /**
     * One page of hydrated rows. Modes: first / next (after $cursor) /
     * prev (before $cursor) / last / at (from $cursor inclusive — redraws
     * the current page).
     *
     * @param  array{0: string, 1: int}|null  $cursor
     * @return array{rows: array<int, array<string, mixed>>, first: array{0: string, 1: int}|null, last: array{0: string, 1: int}|null}
     */
    public function page(AccountStatementScope $scope, string $plan, string $mode, ?array $cursor = null, int $limit = self::PAGE_SIZE): array
    {
        $rows = match ($mode) {
            'next' => $this->finder->fetch($scope, $plan, $cursor, true, $limit),
            'prev' => $this->finder->fetch($scope, $plan, $cursor, false, $limit),
            'last' => $this->finder->fetch($scope, $plan, null, false, $limit),
            'at' => $this->finder->fetch($scope, $plan, $cursor === null ? null : [$cursor[0], $cursor[1] - 1], true, $limit),
            default => $this->finder->fetch($scope, $plan, null, true, $limit),
        };

        $hydrated = $this->hydrate($rows);

        return [
            'rows' => $hydrated,
            'first' => $rows === [] ? null : [AccountStatementProjection::time($rows[0]->transaction_time), (int) $rows[0]->line_id],
            'last' => $rows === [] ? null : [AccountStatementProjection::time(end($rows)->transaction_time), (int) end($rows)->line_id],
        ];
    }

    /**
     * Every matching row in canonical order, in bounded chunks — what the
     * exports write. Same rows, labels and balances as the screen.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(AccountStatementScope $scope, string $plan, int $chunk = self::EXPORT_CHUNK): Generator
    {
        $cursor = null;

        while (true) {
            $raw = $this->finder->fetch($scope, $plan, $cursor, true, $chunk);

            if ($raw === []) {
                return;
            }

            yield from $this->hydrate($raw);

            if (count($raw) < $chunk) {
                return;
            }

            $last = end($raw);
            $cursor = [AccountStatementProjection::time($last->transaction_time), (int) $last->line_id];
        }
    }

    /**
     * Labels (one join query) and running balances (checkpoints) of ≤ one
     * page / chunk of projection rows.
     *
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $positions = [];

        foreach ($rows as $i => $row) {
            $positions[$i] = [(int) $row->account_id, AccountStatementProjection::time($row->transaction_time), (int) $row->line_id];
        }

        $before = $this->balances->balancesBefore($positions);

        $details = DB::table('transaction_lines as tl')
            ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
            ->join('accounts as a', 'a.id', '=', 'tl.account_id')
            ->leftJoin('transactions_types as tt', 'tt.id', '=', 't.transaction_type_id')
            ->leftJoin('transaction_super_types as st', 'st.id', '=', 'tt.transaction_super_type_id')
            ->leftJoin('currencies as cur', 'cur.id', '=', 'tl.currency_id')
            ->leftJoin('projects_costs as pc', 'pc.id', '=', 'tl.project_cost_id')
            ->leftJoin('projects as p', 'p.id', '=', 'pc.project_id')
            ->whereIn('tl.id', array_map(fn ($row) => (int) $row->line_id, $rows))
            ->get([
                'tl.id as line_id',
                't.transaction_number',
                't.description',
                'tl.notes as line_notes',
                'tl.line_role',
                'tl.description as line_description',
                'tt.name as type_name',
                'st.name as classification_name',
                'cur.code as line_currency_code',
                'a.account_code',
                'a.name as account_name',
                'p.code as project_code',
                'p.name as project_name',
            ])
            ->keyBy('line_id');

        $result = [];

        foreach ($rows as $i => $row) {
            $detail = $details[(int) $row->line_id] ?? null;
            $debit = AccountStatementProjection::money($row->debit);
            $credit = AccountStatementProjection::money($row->credit);

            $result[] = [
                'account_id' => (int) $row->account_id,
                'line_id' => (int) $row->line_id,
                'date' => AccountStatementProjection::time($row->transaction_time),
                'transaction_number' => $detail?->transaction_number,
                'type_name' => $detail?->type_name,
                'classification_name' => $detail?->classification_name,
                'description' => $detail?->description,
                'notes' => $detail?->line_notes,
                'account_label' => $detail ? self::accountLabel($detail->account_code, $detail->account_name) : null,
                'project_label' => $detail ? self::projectLabel($detail->project_code, $detail->project_name) : null,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => bcadd($before[$i], bcsub($debit, $credit, 2), 2),
                'currency_code' => $detail?->line_currency_code,
                'line_role_label' => TransactionLineRole::labelFor($detail?->line_role) ?? '—',
                'line_description' => self::displayOrDash($detail?->line_description),
            ];
        }

        return $result;
    }

    public static function accountLabel(?string $code, ?string $name): string
    {
        return trim(($code ? $code.' - ' : '').(string) $name);
    }

    /**
     * "code - name (CUR)" for pickers and filter chips. The currency is
     * appended only when the name does not already end with that code as a
     * word ("… - USD", "… USD"), so "… - USD (USD)" never appears.
     * Presentation only: stored names are untouched.
     */
    public static function accountDisplayLabel(?string $code, ?string $name, ?string $currencyCode): string
    {
        $label = self::accountLabel($code, $name);
        $currencyCode = trim((string) $currencyCode);

        if ($currencyCode === '' || preg_match('/(^|[\s\-–(])'.preg_quote($currencyCode, '/').'\)?$/u', $label) === 1) {
            return $label;
        }

        return "{$label} ({$currencyCode})";
    }

    public static function projectLabel(?string $code, ?string $name): ?string
    {
        if (blank($code) && blank($name)) {
            return null;
        }

        return filled($code) && filled($name) ? "{$code} - {$name}" : (string) ($code ?: $name);
    }

    /**
     * Approved historical-display convention: a genuinely NULL/blank value
     * renders as "—".
     */
    private static function displayOrDash(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : '—';
    }
}
