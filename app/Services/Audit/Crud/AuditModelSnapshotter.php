<?php

namespace App\Services\Audit\Crud;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns an approved model into the bounded audited-field payloads
 * AuditLogger stores (OMS Task 9B.2).
 *
 * Two operations only:
 *
 *  - snapshot()    — the full audited-field state, used for `created`
 *                    (after save), `deleted` (immediately BEFORE deletion)
 *                    and `restored` (after restore);
 *  - pendingDiff() — the changed-audited-fields delta, which MUST be taken
 *                    after fill() and before save(), while the model still
 *                    knows both sides.
 *
 * Only columns in the subject's closed $auditedFields allowlist are ever
 * read, so `created_at`/`updated_at`/`deleted_at`/`created_by`/`updated_by`
 * and any future technical column are excluded by construction rather than
 * filtered out afterwards. Values are read through Eloquent's casts and
 * normalized to JSON-safe scalars here; AuditRedactor and
 * AuditPayloadBounder still run afterwards inside AuditLogger.
 *
 * Rows are assembled keyed by real COLUMN names — that is what the value
 * policies and relation-label lookups need — and are renamed to their
 * semantic audit field names ($fieldAliases) once, as the final step.
 */
final class AuditModelSnapshotter
{
    /**
     * Per-instance memo for relation-label lookups, keyed by
     * subject|foreign key|value. One AuditedCrudService call resolves at
     * most the old and the new side of each labelled foreign key, so this
     * bounds a single logical action to one query per DISTINCT related
     * record and cannot go stale across requests (the service, and with it
     * this snapshotter, is built fresh per resolution).
     *
     * @var array<string, ?string>
     */
    private array $labelCache = [];

    public function __construct(private readonly AuditSubjectRegistry $registry) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Model $model): array
    {
        $definition = $this->registry->definitionFor($model);

        $row = $this->collect($definition, $model, original: false);
        $row = $this->withRelationLabels($definition, $row, $definition->auditedFields);

        return $this->applyFieldAliases($definition, $row);
    }

    public function pendingDiff(Model $model): AuditFieldDiff
    {
        $definition = $this->registry->definitionFor($model);

        $dirty = $model->getDirty();

        $changedColumns = array_values(array_filter(
            $definition->auditedFields,
            static fn (string $field): bool => array_key_exists($field, $dirty),
        ));

        if ($changedColumns === []) {
            return new AuditFieldDiff([], [], []);
        }

        // Both sides are collected in full first so a subject value policy
        // can key off a sibling column on its own side (settings.value is
        // judged against that side's settings.key), then narrowed to the
        // fields that actually changed.
        $oldRow = $this->only($this->collect($definition, $model, original: true), $changedColumns);
        $newRow = $this->only($this->collect($definition, $model, original: false), $changedColumns);

        // Each side is labelled from ITS OWN foreign key value, so the
        // pre-change side describes the record the FK actually pointed at
        // before the mutation.
        $oldRow = $this->withRelationLabels($definition, $oldRow, $changedColumns);
        $newRow = $this->withRelationLabels($definition, $newRow, $changedColumns);

        return new AuditFieldDiff(
            old: $this->applyFieldAliases($definition, $oldRow),
            new: $this->applyFieldAliases($definition, $newRow),
            // changed_fields stays a list of semantic BUSINESS fields. A
            // relationship label is a readability snapshot attached to its
            // foreign key, not a field a user changed, so it never appears
            // here — `project_id` does.
            changed: array_map(
                static fn (string $column): string => $definition->auditFieldName($column),
                $changedColumns,
            ),
        );
    }

    public function label(Model $model): ?string
    {
        return $this->registry->definitionFor($model)->label($model);
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(AuditSubjectDefinition $definition, Model $model, bool $original): array
    {
        $raw = [];

        foreach ($definition->auditedFields as $field) {
            $raw[$field] = $this->normalize(
                $original ? $model->getOriginal($field) : $model->getAttribute($field),
                $model,
                $field,
            );
        }

        $row = [];

        foreach ($raw as $field => $value) {
            $row[$field] = $definition->applyValuePolicy($field, $value, $raw);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function only(array $row, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $result[$field] = $row[$field];
            }
        }

        return $result;
    }

    /**
     * Adds one bounded label per foreign key present in $fields. The FK
     * scalar itself always stays in the payload — the label is an extra,
     * never a replacement, and a related model or collection is never
     * serialized. A foreign key that is not part of this payload (an
     * unrelated field changed) triggers no lookup at all.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function withRelationLabels(AuditSubjectDefinition $definition, array $row, array $fields): array
    {
        foreach ($definition->relationLabels as $foreignKey => $_resolver) {
            if (! in_array($foreignKey, $fields, true) || ! array_key_exists($foreignKey, $row)) {
                continue;
            }

            $label = $this->resolveRelationLabel($definition, $foreignKey, $row[$foreignKey]);

            if ($label !== null) {
                $row[$definition->relationLabelKey($foreignKey)] = $label;
            }
        }

        return $row;
    }

    private function resolveRelationLabel(AuditSubjectDefinition $definition, string $foreignKey, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cacheKey = $definition->alias.'|'.$foreignKey.'|'.$value;

        if (array_key_exists($cacheKey, $this->labelCache)) {
            return $this->labelCache[$cacheKey];
        }

        return $this->labelCache[$cacheKey] = $definition->relationLabel($foreignKey, $value);
    }

    /**
     * Renames database columns to their semantic audit field names. Runs
     * last, so value policies and relation-label lookups have already seen
     * the true column names. Derived keys (a relation label) are already
     * emitted under their final name and are left alone.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyFieldAliases(AuditSubjectDefinition $definition, array $row): array
    {
        if ($definition->fieldAliases === []) {
            return $row;
        }

        $result = [];

        foreach ($row as $key => $value) {
            $result[is_string($key) ? $definition->auditFieldName($key) : $key] = $value;
        }

        return $result;
    }

    /**
     * Date-cast columns are stored as plain `Y-m-d` rather than the full
     * ATOM timestamp AuditPayloadBounder would otherwise produce for a
     * DateTimeInterface — every date column on this phase's models is a
     * business date, not an instant.
     */
    private function normalize(mixed $value, Model $model, string $field): mixed
    {
        if (! $value instanceof DateTimeInterface) {
            return $value;
        }

        $cast = $model->getCasts()[$field] ?? null;

        if ($cast === 'date' || (is_string($cast) && str_starts_with($cast, 'date:'))) {
            return $value->format('Y-m-d');
        }

        return $value->format(DATE_ATOM);
    }
}
