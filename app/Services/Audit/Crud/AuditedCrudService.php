<?php

namespace App\Services\Audit\Crud;

use App\Enums\AuditFailureMode;
use App\Services\Audit\AuditActorResolver;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditRecordRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single audited write path for the general/master-data models approved
 * in OMS Task 9B.2, and the component that owns the transaction.
 *
 * Why a service rather than model observers: Filament's Create/Edit pages
 * only open a transaction when the panel enables
 * `->databaseTransactions()`, and Actions only when `->databaseTransaction()`
 * is set on each one — neither is enabled in this application (verified
 * against Filament\Pages\Concerns\CanUseDatabaseTransactions and
 * Filament\Actions\Concerns\CanUseDatabaseTransactions). An `created`/
 * `updated`/`deleted` observer therefore fires with the business row ALREADY
 * committed, so a failing REQUIRED audit insert would leave a mutation with
 * no audit row — exactly the design 9B.2 forbids. Every method here instead
 * wraps the mutation and its AuditEvent in one DB::transaction(), so they
 * commit or roll back together.
 *
 * Nesting is safe and intended: called from inside a caller's existing
 * transaction, Laravel uses a savepoint, and a rollback of the outer
 * transaction discards the AuditEvent along with the business change.
 *
 * Because auditing lives here and not in a global model hook, code that
 * deliberately writes these tables outside real user actions — seeders
 * (DatabaseSeeder::seedSettings()), migrations, factories, test fixtures,
 * permission synchronisation — simply does not produce audit history. No
 * suppression switch exists, or is needed (see docs/DECISIONS_LOG.md).
 */
final class AuditedCrudService
{
    private const EVENT_CATEGORY = 'crud';

    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AuditSubjectRegistry $registry,
        private readonly AuditModelSnapshotter $snapshotter,
        private readonly AuditActorResolver $actorResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    public function create(Model $model, array $attributes = []): Model
    {
        $definition = $this->registry->definitionFor($model);

        return DB::transaction(function () use ($model, $attributes, $definition): Model {
            if ($attributes !== []) {
                $model->fill($attributes);
            }

            $model->save();

            $this->record(
                definition: $definition,
                model: $model,
                action: 'created',
                oldValues: null,
                newValues: $this->snapshotter->snapshot($model),
                changedFields: null,
            );

            return $model;
        });
    }

    /**
     * The relation-manager create path (Projects -> تكاليف المشروع). The
     * relationship performs the insert so the owner's foreign key is
     * applied exactly as Filament's own default handler would; only the
     * transaction and the audit row are added.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createViaRelationship(Relation $relationship, array $attributes): Model
    {
        $model = $relationship->getRelated()->newInstance();
        $definition = $this->registry->definitionFor($model);

        return DB::transaction(function () use ($relationship, $model, $attributes, $definition): Model {
            $model->fill($attributes);
            $relationship->save($model);

            $this->record(
                definition: $definition,
                model: $model,
                action: 'created',
                oldValues: null,
                newValues: $this->snapshotter->snapshot($model),
                changedFields: null,
            );

            return $model;
        });
    }

    /**
     * No AuditEvent is written when no audited business field changed — a
     * form re-save that touches nothing (or touches only `updated_by`, which
     * HasUserTracking rewrites on every save and which is not an audited
     * field) is not an auditable state change.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model
    {
        $definition = $this->registry->definitionFor($model);

        return DB::transaction(function () use ($model, $attributes, $definition): Model {
            $model->fill($attributes);

            $diff = $this->snapshotter->pendingDiff($model);

            $model->save();

            if ($diff->isEmpty()) {
                return $model;
            }

            $this->record(
                definition: $definition,
                model: $model,
                action: 'updated',
                oldValues: $diff->old,
                newValues: $diff->new,
                changedFields: $diff->changed,
            );

            return $model;
        });
    }

    public function delete(Model $model): bool
    {
        $definition = $this->registry->definitionFor($model);

        return DB::transaction(function () use ($model, $definition): bool {
            // Both the payload and the label are captured while the record
            // is still intact; subject_key is the primary key, which
            // survives the delete and is never re-resolved later.
            $snapshot = $this->snapshotter->snapshot($model);
            $label = $definition->label($model);
            $subjectKey = $this->subjectKey($model);

            $deleted = (bool) $model->delete();

            $this->record(
                definition: $definition,
                model: $model,
                action: 'deleted',
                oldValues: $snapshot,
                newValues: null,
                changedFields: null,
                subjectKey: $subjectKey,
                subjectLabel: $label,
            );

            return $deleted;
        });
    }

    /**
     * Supported for completeness of the SoftDeletes lifecycle. No Filament
     * resource in this phase exposes a restore action — OMS removed every
     * Restore/ForceDelete action by design (see OMS_Master_Reference.md) —
     * so this has no UI caller today.
     */
    public function restore(Model $model): bool
    {
        $definition = $this->registry->definitionFor($model);

        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            throw new InvalidArgumentException(sprintf(
                '[%s] does not use SoftDeletes and cannot be restored.',
                $model::class,
            ));
        }

        return DB::transaction(function () use ($model, $definition): bool {
            $restored = (bool) $model->restore();

            $this->record(
                definition: $definition,
                model: $model,
                action: 'restored',
                oldValues: null,
                newValues: $this->snapshotter->snapshot($model),
                changedFields: null,
            );

            return $restored;
        });
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<int, string>|null  $changedFields
     */
    private function record(
        AuditSubjectDefinition $definition,
        Model $model,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?array $changedFields,
        ?string $subjectKey = null,
        ?string $subjectLabel = null,
    ): void {
        $this->logger->record(
            new AuditRecordRequest(
                eventCategory: self::EVENT_CATEGORY,
                eventAction: $action,
                actor: $this->actorResolver->resolve(),
                subjectType: $definition->alias,
                subjectKey: $subjectKey ?? $this->subjectKey($model),
                subjectLabel: $subjectLabel ?? $definition->label($model),
                oldValues: $oldValues,
                newValues: $newValues,
                changedFields: $changedFields,
            ),
            AuditFailureMode::Required,
        );
    }

    private function subjectKey(Model $model): ?string
    {
        $key = $model->getKey();

        return $key === null ? null : (string) $key;
    }
}
