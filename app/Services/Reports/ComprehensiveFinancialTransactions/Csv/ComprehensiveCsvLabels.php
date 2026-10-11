<?php

namespace App\Services\Reports\ComprehensiveFinancialTransactions\Csv;

use App\Enums\TransactionLineRole;
use App\Services\Reports\AccountStatement\Csv\AccountStatementCsvFormat;
use Illuminate\Support\Facades\DB;

/**
 * Every label of a movement row that comes from master data, loaded ONCE
 * per export (per segment process) and already CSV-encoded — never one
 * query per line.
 *
 * Bounded by master data, not by lines. Same sources and conventions as
 * ComprehensiveFinancialTransactionsReportService::joinedLinesQuery() /
 * row():
 *  - accounts, account types, bank types, currencies, transaction types and
 *    classifications, users: joined UNGUARDED (soft-deleted lookups still
 *    label their history);
 *  - project cost items and projects: only non-deleted ones label a line
 *    (both joins carry deleted_at IS NULL), so a deleted cost item reads
 *    "غير مرتبط بمشروع" and contributes no note.
 */
final class ComprehensiveCsvLabels
{
    /**
     * @param  array<int, array{0: string, 1: string}>  $types  type id => [type cell, classification cell]
     * @param  array<int, string>  $currencies  currency id => cell
     * @param  array<int, string>  $currencyLabels  currency id => plain label (the summaries' key)
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string}>  $accounts  account id => [account cell, bank type cell, account type cell, raw trimmed notes]
     * @param  array<int, array{0: string, 1: string}>  $costs  live project cost id => [project cell, raw trimmed notes]
     * @param  array<int, string>  $users  user id => cell
     * @param  array<string, string>  $roles  line_role => cell
     */
    public function __construct(
        public readonly array $types,
        public readonly array $currencies,
        public readonly array $currencyLabels,
        public readonly array $accounts,
        public readonly array $costs,
        public readonly array $users,
        public readonly array $roles,
    ) {}

    public static function load(): self
    {
        $text = AccountStatementCsvFormat::text(...);
        $unspecified = $text(ComprehensiveCsvFormat::UNSPECIFIED);

        $superTypes = DB::table('transaction_super_types')->pluck('name', 'id')->all();
        $types = [];

        foreach (DB::table('transactions_types')->get(['id', 'name', 'transaction_super_type_id']) as $type) {
            $super = $type->transaction_super_type_id === null ? null : ($superTypes[$type->transaction_super_type_id] ?? null);
            $types[(int) $type->id] = [
                $type->name ? $text((string) $type->name) : $unspecified,
                $super ? $text((string) $super) : $unspecified,
            ];
        }

        $currencies = [];
        $currencyLabels = [];

        foreach (DB::table('currencies')->get(['id', 'name', 'code']) as $currency) {
            $currencyLabels[(int) $currency->id] = self::currencyLabel($currency->name, $currency->code);
            $currencies[(int) $currency->id] = $text($currencyLabels[(int) $currency->id]);
        }

        $accountTypes = DB::table('accounts_type')->pluck('name', 'id')->all();
        $bankTypes = DB::table('bank_types')->pluck('name', 'id')->all();
        $accounts = [];

        foreach (DB::table('accounts')->select(['id', 'account_code', 'name', 'notes', 'account_type_id', 'bank_type_id'])->lazyById(5000, 'id') as $account) {
            $bankType = $account->bank_type_id === null ? null : ($bankTypes[$account->bank_type_id] ?? null);
            $accountType = $account->account_type_id === null ? null : ($accountTypes[$account->account_type_id] ?? null);

            $accounts[(int) $account->id] = [
                $text(trim(($account->account_code ? $account->account_code.' - ' : '').$account->name)),
                $bankType ? $text((string) $bankType) : $text(ComprehensiveCsvFormat::DASH),
                $accountType ? $text((string) $accountType) : $unspecified,
                trim((string) $account->notes),
            ];
        }

        $costs = [];

        foreach (DB::table('projects_costs as pc')
            ->leftJoin('projects as p', function ($join) {
                $join->on('p.id', '=', 'pc.project_id')->whereNull('p.deleted_at');
            })
            ->whereNull('pc.deleted_at')
            ->get(['pc.id', 'pc.notes', 'p.name']) as $cost) {
            $costs[(int) $cost->id] = [
                $text($cost->name ?: ComprehensiveCsvFormat::NO_PROJECT),
                trim((string) $cost->notes),
            ];
        }

        $users = [];

        foreach (DB::table('users')->get(['id', 'name']) as $user) {
            $users[(int) $user->id] = $user->name ? $text((string) $user->name) : ComprehensiveCsvFormat::NO_USER;
        }

        $roles = [];

        foreach (TransactionLineRole::cases() as $role) {
            $roles[$role->value] = $text(TransactionLineRole::labelFor($role->value) ?? ComprehensiveCsvFormat::DASH);
        }

        return new self($types, $currencies, $currencyLabels, $accounts, $costs, $users, $roles);
    }

    /**
     * The report's currency label ("دولار (USD)").
     */
    public static function currencyLabel(?string $name, ?string $code): string
    {
        if (! $name && ! $code) {
            return ComprehensiveCsvFormat::UNSPECIFIED;
        }

        return trim(($name ?: '').($code ? " ({$code})" : ''));
    }
}
