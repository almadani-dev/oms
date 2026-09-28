<?php

namespace App\Filament\Tables;

use App\Support\Search\ArabicSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search predicates shared by the five financial operation lists (صرف مبلغ
 * تنفيذ، صرف مبلغ، استلام مبلغ، تحويل عام، مصروف عام). Each list calls only the
 * pieces its columns actually show, from its own table-level searchable([...]).
 *
 * Every predicate goes through ArabicSearch (alef folding for names, identifier
 * semantics for numbers/codes, literal `%`/`_`) and reaches related rows through
 * whereHas — a correlated EXISTS, never a join — so the related models'
 * SoftDeletes scopes apply. A word that cleans to nothing adds no predicate and,
 * in particular, no always-true EXISTS.
 */
final class FinancialListSearch
{
    /**
     * One EXISTS on the operation's transaction: its number (identifier), plus —
     * only where the page shows them — the partner name, the transaction type
     * name and the account code/name of any of its lines.
     */
    public static function transaction(Builder $query, string $search, bool $partner, bool $type, bool $accounts): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas('transaction', fn (Builder $transaction): Builder => $transaction->where(
            function (Builder $transaction) use ($search, $partner, $type, $accounts): void {
                ArabicSearch::whereContainsIdentifier($transaction, 'transaction_number', $search);

                if ($partner) {
                    $transaction->orWhereHas('partner', fn (Builder $related): Builder => ArabicSearch::whereContainsText($related, 'name', $search));
                }

                if ($type) {
                    $transaction->orWhereHas('transactionType', fn (Builder $related): Builder => ArabicSearch::whereContainsText($related, 'name', $search));
                }

                if ($accounts) {
                    $transaction->orWhereHas('lines.account', fn (Builder $account): Builder => $account->where(
                        function (Builder $account) use ($search): void {
                            ArabicSearch::whereContainsIdentifier($account, 'account_code', $search);
                            ArabicSearch::whereContainsText($account, 'name', $search, 'or');
                        },
                    ));
                }
            },
        ));
    }

    /**
     * One EXISTS on the project reached through $relation: its code (identifier)
     * or name (text), or its المشروع الرئيسي's code or name.
     */
    public static function project(Builder $query, string $relation, string $search): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $project): Builder => $project->where(
            function (Builder $project) use ($search): void {
                ArabicSearch::whereContainsIdentifier($project, 'code', $search);
                ArabicSearch::whereContainsText($project, 'name', $search, 'or');

                $project->orWhereHas('projectSuper', fn (Builder $super): Builder => $super->where(
                    function (Builder $super) use ($search): void {
                        ArabicSearch::whereContainsIdentifier($super, 'code', $search);
                        ArabicSearch::whereContainsText($super, 'name', $search, 'or');
                    },
                ));
            },
        ));
    }

    /**
     * The operation's own partner (general exchanges/expenses keep one on the
     * row; the list falls back to the transaction's partner, which transaction()
     * covers).
     */
    public static function ownPartner(Builder $query, string $search): Builder
    {
        if (ArabicSearch::clean($search) === '') {
            return $query;
        }

        return $query->whereHas('partner', fn (Builder $partner): Builder => ArabicSearch::whereContainsText($partner, 'name', $search));
    }

    /**
     * Exact equality on the listed amount columns, only when the word is a
     * plain number (ArabicSearch::numeric()). Never a LIKE over a cast decimal.
     *
     * @param  list<string>  $columns
     */
    public static function amounts(Builder $query, array $columns, string $search): Builder
    {
        $amount = ArabicSearch::numeric($search);

        if ($amount === null) {
            return $query;
        }

        foreach ($columns as $column) {
            $query->orWhere($query->qualifyColumn($column), $amount);
        }

        return $query;
    }
}
