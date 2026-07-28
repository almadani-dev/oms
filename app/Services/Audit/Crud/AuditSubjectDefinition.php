<?php

namespace App\Services\Audit\Crud;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything general-CRUD auditing (OMS Task 9B.2) needs to know about one
 * approved model class. Built once, in AuditSubjectRegistry — never derived
 * from the class name, never reflected out of $fillable.
 *
 * $auditedFields is a closed allowlist of real business column names, which
 * is what keeps technical/noise columns (`created_at`, `updated_at`,
 * `deleted_at`, `created_by`, `updated_by`, `id`, observer-maintained
 * flags) out of every payload structurally rather than by a denylist that
 * would have to be kept in step with future migrations.
 *
 * $fieldAliases renames a database column to a safe SEMANTIC audit field
 * name in the emitted payload and in changed_fields. It exists for exactly
 * one situation: a legitimate business column whose literal name collides
 * with AuditRedactor's secret denylist. `settings.key` is the only such
 * column today — it is a setting's identifier, not key material, but a bare
 * `key` field is (correctly, globally) treated as secret-shaped by the
 * redactor. Aliasing it to `setting_name` preserves rename history without
 * weakening the redactor for every other subject. It is NOT a general
 * escape hatch: values still pass through the subject's own value policy,
 * AuditRedactor and AuditPayloadBounder afterwards, exactly as before.
 *
 * $relationLabels maps a foreign-key column in $auditedFields to a resolver
 * producing ONE bounded human-readable label for it. The resolver is handed
 * the foreign key VALUE — never the owning model — so the pre-change side
 * of an update is labelled from the ORIGINAL key rather than from a
 * relationship object that already reflects the new one. The FK scalar is
 * always stored as itself; the label is stored alongside under a derived
 * key (`project_id` -> `project_label`). A related model or collection is
 * never serialized.
 */
final class AuditSubjectDefinition
{
    public const MAX_LABEL_LENGTH = 255;

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, string>  $auditedFields  real column names
     * @param  Closure(Model): ?string  $labelResolver
     * @param  array<string, Closure(mixed): ?string>  $relationLabels  FK column => resolver taking the FK value
     * @param  ?Closure(string, mixed, array<string, mixed>): mixed  $valuePolicy
     * @param  array<string, string>  $fieldAliases  column name => emitted audit field name
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly string $alias,
        public readonly array $auditedFields,
        private readonly Closure $labelResolver,
        public readonly array $relationLabels = [],
        private readonly ?Closure $valuePolicy = null,
        public readonly array $fieldAliases = [],
    ) {}

    public function label(Model $model): ?string
    {
        return self::bound(($this->labelResolver)($model));
    }

    /**
     * The emitted audit field name for a database column. Identity for every
     * column without an explicit alias.
     */
    public function auditFieldName(string $column): string
    {
        return $this->fieldAliases[$column] ?? $column;
    }

    /**
     * The payload key a foreign key's bounded label snapshot is stored under.
     */
    public function relationLabelKey(string $foreignKey): string
    {
        return preg_replace('/_id$/', '', $this->auditFieldName($foreignKey)).'_label';
    }

    /**
     * @param  mixed  $foreignKeyValue  the side-specific FK value (original for
     *                                  old_values, current for new_values)
     */
    public function relationLabel(string $foreignKey, mixed $foreignKeyValue): ?string
    {
        $resolver = $this->relationLabels[$foreignKey] ?? null;

        if ($resolver === null || $foreignKeyValue === null) {
            return null;
        }

        return self::bound($resolver($foreignKeyValue));
    }

    /**
     * A last, subject-specific gate applied to one already-collected field
     * value before it is handed to AuditRedactor/AuditPayloadBounder. Only
     * `setting` uses one today (see SettingValuePolicy) — every other
     * subject stores its allowlisted business columns verbatim.
     *
     * Runs while the row is still keyed by real COLUMN names, before any
     * $fieldAliases renaming, so a policy can key off a sibling column by
     * its true database name.
     *
     * @param  array<string, mixed>  $row  the whole side (old or new) being built
     */
    public function applyValuePolicy(string $field, mixed $value, array $row): mixed
    {
        if ($this->valuePolicy === null) {
            return $value;
        }

        return ($this->valuePolicy)($field, $value, $row);
    }

    private static function bound(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }
}
