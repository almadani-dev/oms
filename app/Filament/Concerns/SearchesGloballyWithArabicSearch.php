<?php

namespace App\Filament\Concerns;

use App\Support\Search\ArabicSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Topbar global search through ArabicSearch for a resource whose keys are
 * plain columns of its own model. The resource declares each key and its
 * semantics once, in globalSearchFields():
 *
 *  - 'text'       → human Arabic text: alef folding on both sides;
 *  - 'identifier' → codes, numbers, emails, file names: cleaned, never folded.
 *
 * Both escape `%` / `_` literally. Every word of the input must match one of
 * the keys — the same rule as the list tables. The query still starts from
 * the resource's getGlobalSearchEloquentQuery() (SoftDeletes and any
 * visibility scope included), and Filament's canGloballySearch() and
 * per-result canView() checks are untouched. Resources with relation keys or
 * person-name semantics (accounts, projects, Muwakha families) keep their own
 * explicit overrides instead.
 */
trait SearchesGloballyWithArabicSearch
{
    /**
     * @return array<string, 'text'|'identifier'>
     */
    abstract protected static function globalSearchFields(): array;

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return array_keys(static::globalSearchFields());
    }

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        foreach (ArabicSearch::words($search) as $word) {
            $query->where(function (Builder $query) use ($word): void {
                $boolean = 'and';

                foreach (static::globalSearchFields() as $column => $semantics) {
                    $semantics === 'identifier'
                        ? ArabicSearch::whereContainsIdentifier($query, $column, $word, $boolean)
                        : ArabicSearch::whereContainsText($query, $column, $word, $boolean);

                    $boolean = 'or';
                }
            });
        }
    }
}
