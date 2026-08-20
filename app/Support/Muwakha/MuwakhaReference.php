<?php

namespace App\Support\Muwakha;

use App\Models\AccountType;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectSuper;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single place the Muwakha feature resolves its reference data, and the
 * single definition of which Projects are eligible for family linkage.
 *
 * Nothing here is a hard-coded database id. The account type is resolved by
 * name and the Muwakha root by name — so the feature is correct on any
 * installation regardless of the order in which those rows were entered by
 * hand. Every resolver fails closed via MuwakhaReferenceException rather than
 * returning null or guessing.
 *
 * CURRENCY IS A CHOICE, NOT A CONSTANT. A family account's currency is picked
 * by the operator from the live `currencies` table; this class only supplies
 * the option list, the server-side validity check and the single display-name
 * convention (`currencies.name`, the same label AccountForm's currency Select
 * and AccountsTable's currency filter already use). There is no Muwakha
 * currency default and no Muwakha-specific currency behaviour.
 *
 * WHY THE ROOT IS A ProjectSuper. OMS has no project-to-project parent
 * relation; `projects` carries no self-referencing column. The only hierarchy
 * is `projects_super` (المشروع الرئيسي) -> `projects.project_super_id`. A
 * Project is therefore eligible for Muwakha family linkage precisely when its
 * `project_super_id` points at the `مشروع المؤاخاة` root.
 *
 * A useful consequence: because a ProjectSuper is a different table from
 * `projects`, the root can never appear in a project Select at all. "The root
 * project itself is not selectable" holds by construction, not by a filter a
 * forged request could bypass.
 */
final class MuwakhaReference
{
    /** The account type every Muwakha family account must carry. */
    public const ACCOUNT_TYPE_NAME = 'أفراد';

    /** The root ProjectSuper whose child Projects are Muwakha projects. */
    public const PROJECT_SUPER_NAME = 'مشروع المؤاخاة';

    /**
     * `accounts_type.name` carries no unique constraint, so "exactly one" is
     * asserted here rather than assumed. Soft-deleted types are excluded: a
     * deleted lookup must not silently become the type of a new live account.
     */
    public static function accountTypeId(): int
    {
        $matches = AccountType::where('name', self::ACCOUNT_TYPE_NAME)->pluck('id');

        if ($matches->isEmpty()) {
            throw MuwakhaReferenceException::accountTypeMissing(self::ACCOUNT_TYPE_NAME);
        }

        if ($matches->count() > 1) {
            throw MuwakhaReferenceException::accountTypeAmbiguous(
                self::ACCOUNT_TYPE_NAME,
                $matches->count(),
            );
        }

        return (int) $matches->first();
    }

    /**
     * The authoritative selectable-currency query: every live OMS currency,
     * ordered by its display name. Soft-deleted currencies are excluded by the
     * model's own scope — a retired currency must not become the currency of a
     * new family account.
     *
     * Used by the form's Select AND by the service's server-side check, so a
     * forged request is validated against exactly the same set the dropdown was
     * built from.
     */
    public static function selectableCurrenciesQuery(): Builder
    {
        return Currency::query()->orderBy('name');
    }

    /**
     * [id => name] for the family-account currency Select.
     *
     * @return array<int, string>
     */
    public static function currencyOptions(): array
    {
        return self::selectableCurrenciesQuery()->pluck('name', 'id')->all();
    }

    /**
     * The submitted currency as a live Currency, or null when it is absent,
     * unknown or soft-deleted — so the caller can turn it into a field-level
     * Arabic message rather than a 500.
     */
    public static function findSelectableCurrency(mixed $currencyId): ?Currency
    {
        if (blank($currencyId)) {
            return null;
        }

        return self::selectableCurrenciesQuery()->whereKey($currencyId)->first();
    }

    /**
     * The one display-name convention for a currency in the Muwakha feature —
     * `currencies.name`, exactly what AccountForm's currency Select and
     * AccountsTable's currency filter already show. Every place that renders or
     * embeds a currency label (the account name, the table column, the view
     * page, both exports) resolves it through here, so no second convention can
     * appear and no Arabic currency name is ever hard-coded.
     */
    public static function currencyDisplayName(?Currency $currency): ?string
    {
        $name = trim((string) $currency?->name);

        return $name === '' ? null : $name;
    }

    /**
     * `projects_super.name` carries no unique constraint either, so the same
     * exactly-one assertion applies.
     */
    public static function projectSuperId(): int
    {
        $matches = ProjectSuper::where('name', self::PROJECT_SUPER_NAME)->pluck('id');

        if ($matches->isEmpty()) {
            throw MuwakhaReferenceException::projectSuperMissing(self::PROJECT_SUPER_NAME);
        }

        if ($matches->count() > 1) {
            throw MuwakhaReferenceException::projectSuperAmbiguous(
                self::PROJECT_SUPER_NAME,
                $matches->count(),
            );
        }

        return (int) $matches->first();
    }

    /**
     * The authoritative eligible-project query. Used by the Select options AND
     * by server-side validation, so a forged request is checked against
     * exactly the same rule the dropdown was built from — there is no second,
     * looser definition anywhere.
     */
    public static function eligibleProjectsQuery(): Builder
    {
        return Project::query()
            ->where('project_super_id', self::projectSuperId())
            ->orderBy('name');
    }

    /**
     * Whether one project id is a linkable Muwakha project. Returns false —
     * rather than throwing — for a null/absent/soft-deleted project, so
     * validation can turn it into a field-level Arabic message.
     */
    public static function isEligibleProject(mixed $projectId): bool
    {
        if (blank($projectId)) {
            return false;
        }

        return self::eligibleProjectsQuery()->whereKey($projectId)->exists();
    }

    /**
     * [id => name] for the eligible-project Select. Bounded to the Muwakha
     * root's own children, which is a small set by nature, so this is a safe
     * preload.
     *
     * @return array<int, string>
     */
    public static function eligibleProjectOptions(): array
    {
        return self::eligibleProjectsQuery()->pluck('name', 'id')->all();
    }
}
