<?php

namespace App\Services\Reports\AccountStatement\Csv;

use App\Enums\TransactionLineRole;
use App\Services\Reports\AccountStatement\AccountStatementReport;
use App\Services\Reports\AccountStatement\AccountStatementScope;
use Illuminate\Support\Facades\DB;

/**
 * Every label a movement row shows, loaded ONCE per export (per segment
 * process) and already CSV-encoded — never one query per movement.
 *
 * Bounded by master data, not by movements: the covered accounts, every
 * transaction type and classification, currency, project cost item and line
 * role. Same sources and conventions as AccountStatementReport::hydrate()
 * (soft-deleted lookups still label their history).
 */
final class AccountStatementCsvLabels
{
    /**
     * @param  array<int, string>  $accounts  account_id => "code - name"
     * @param  array<int, string>  $types  transaction_type_id => type name
     * @param  array<int, string>  $classes  transaction_type_id => classification name
     * @param  array<int, string>  $currencies  currency_id => code
     * @param  array<int, string>  $projects  project_cost_id => "code - name"
     * @param  array<string, string>  $roles  line_role => Arabic label
     */
    public function __construct(
        public readonly array $accounts,
        public readonly array $types,
        public readonly array $classes,
        public readonly array $currencies,
        public readonly array $projects,
        public readonly array $roles,
    ) {}

    public static function load(AccountStatementScope $scope): self
    {
        $text = AccountStatementCsvFormat::text(...);

        $accounts = [];

        foreach (array_chunk(array_keys($scope->accountCurrencies), 5000) as $ids) {
            foreach (DB::table('accounts')->whereIn('id', $ids)->get(['id', 'account_code', 'name']) as $account) {
                $accounts[(int) $account->id] = $text(AccountStatementReport::accountLabel($account->account_code, $account->name));
            }
        }

        $classNames = DB::table('transaction_super_types')->pluck('name', 'id')->all();
        $types = [];
        $classes = [];

        foreach (DB::table('transactions_types')->get(['id', 'name', 'transaction_super_type_id']) as $type) {
            $types[(int) $type->id] = $text($type->name);
            $classes[(int) $type->id] = $text($classNames[$type->transaction_super_type_id] ?? null);
        }

        $currencies = array_map($text, DB::table('currencies')->pluck('code', 'id')->all());

        $projects = [];

        foreach (DB::table('projects_costs as pc')->leftJoin('projects as p', 'p.id', '=', 'pc.project_id')->get(['pc.id', 'p.code', 'p.name']) as $cost) {
            $projects[(int) $cost->id] = $text(AccountStatementReport::projectLabel($cost->code, $cost->name));
        }

        $roles = [];

        foreach (TransactionLineRole::cases() as $role) {
            $roles[$role->value] = $text(TransactionLineRole::labelFor($role->value));
        }

        return new self($accounts, $types, $classes, $currencies, $projects, $roles);
    }
}
