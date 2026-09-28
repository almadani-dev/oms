<?php

namespace App\Filament\Tables;

use App\Models\Partner;
use App\Models\Project;
use App\Support\Search\ArabicSearch;
use Closure;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Project and partner filters for the financial operation lists, searched on
 * the server instead of loading every project/partner into the page.
 *
 * The lists reach the project/partner through different paths (payment →
 * budget → cost → project, receipt → cost → project, transaction.partner_id,
 * the row's own partner_id), so SelectFilter::relationship() cannot express
 * them. Each page therefore passes its existing apply closure unchanged; only
 * how the options are found changes: typed search through ArabicSearch, at most
 * OPTIONS_LIMIT results, never preloaded, soft-deleted rows excluded by the
 * models' default scope. Because the filter has no static option list, the
 * active-filter indicator resolves its label itself.
 */
final class FinancialLookupFilters
{
    public const OPTIONS_LIMIT = 50;

    /**
     * @param  Closure(Builder, int|string): Builder  $apply  the page's own project constraint
     */
    public static function project(string $name, string $label, Closure $apply): SelectFilter
    {
        return self::lookup(
            $name,
            $label,
            fn (string $search): array => self::projectOptions($search),
            fn (mixed $value): ?string => self::projectLabel($value),
            $apply,
        );
    }

    /**
     * @param  Closure(Builder, int|string): Builder  $apply  the page's own partner constraint
     * @param  (Closure(Builder): Builder)|null  $scope  the page's eligibility rule (e.g. donors only)
     */
    public static function partner(string $name, string $label, Closure $apply, ?Closure $scope = null): SelectFilter
    {
        return self::lookup(
            $name,
            $label,
            fn (string $search): array => self::partnerOptions($search, $scope),
            fn (mixed $value): ?string => self::partnerLabel($value, $scope),
            $apply,
        );
    }

    /**
     * Up to OPTIONS_LIMIT projects matching $search by code (identifier) or
     * name (text), labelled "code - name". Also used by the report and form
     * Selects (Batch E), which pass their own eligibility as $scope (e.g. the
     * donor report's "projects of the chosen donor").
     *
     * @param  (Closure(Builder): Builder)|null  $scope
     * @return array<int, string>
     */
    public static function projectOptions(string $search, ?Closure $scope = null): array
    {
        return self::projectQuery($scope)
            ->where(function (Builder $query) use ($search): void {
                ArabicSearch::whereContainsIdentifier($query, 'code', $search);
                ArabicSearch::whereContainsText($query, 'name', $search, 'or');
            })
            ->orderBy('code')
            ->orderBy('name')
            ->limit(self::OPTIONS_LIMIT)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Project $project): array => [$project->id => self::projectLabelFor($project)])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function partnerOptions(string $search, ?Closure $scope = null): array
    {
        return self::partnerQuery($scope)
            ->where(fn (Builder $query): Builder => ArabicSearch::whereContainsText($query, 'name', $search))
            ->orderBy('name')
            ->limit(self::OPTIONS_LIMIT)
            ->pluck('name', 'id')
            ->all();
    }

    private static function lookup(string $name, string $label, Closure $findOptions, Closure $optionLabel, Closure $apply): SelectFilter
    {
        return SelectFilter::make($name)
            ->label($label)
            ->searchable()
            ->preload(false)
            ->optionsLimit(self::OPTIONS_LIMIT)
            ->getSearchResultsUsing(fn (?string $search): array => $findOptions($search ?? ''))
            ->getOptionLabelUsing(fn (mixed $value): ?string => blank($value) ? null : $optionLabel($value))
            ->indicateUsing(function (array $state) use ($label, $optionLabel): array {
                $value = $state['value'] ?? null;
                $text = blank($value) ? null : $optionLabel($value);

                return blank($text) ? [] : [Indicator::make("{$label}: {$text}")];
            })
            ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                ? $apply($query, $data['value'])
                : $query);
    }

    /**
     * The "code - name" label of one project within $scope, or null when it is
     * not eligible. A Select that searches on the server resolves its selected
     * value through this, and Filament's `in` validation rejects a value whose
     * label is null — so the scope here is also the Select's eligibility rule.
     *
     * @param  (Closure(Builder): Builder)|null  $scope
     */
    public static function projectLabel(mixed $value, ?Closure $scope = null): ?string
    {
        $project = self::projectQuery($scope)->whereKey($value)->first(['id', 'code', 'name']);

        return $project ? self::projectLabelFor($project) : null;
    }

    /**
     * The name of one partner within $scope (e.g. donors only), or null when it
     * is not eligible — the Select's label and, through Filament's `in` rule,
     * its validation.
     *
     * @param  (Closure(Builder): Builder)|null  $scope
     */
    public static function partnerLabel(mixed $value, ?Closure $scope = null): ?string
    {
        return self::partnerQuery($scope)->whereKey($value)->value('name');
    }

    private static function projectQuery(?Closure $scope): Builder
    {
        $query = Project::query();

        return $scope ? $scope($query) : $query;
    }

    /** "code - name", or whichever of the two exists. */
    private static function projectLabelFor(Project $project): string
    {
        return filled($project->code) && filled($project->name)
            ? "{$project->code} - {$project->name}"
            : (string) ($project->code ?: $project->name);
    }

    private static function partnerQuery(?Closure $scope): Builder
    {
        $query = Partner::query();

        return $scope ? $scope($query) : $query;
    }
}
