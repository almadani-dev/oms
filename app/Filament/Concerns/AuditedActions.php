<?php

namespace App\Filament\Concerns;

use App\Services\Audit\Crud\AuditedCrudService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

/**
 * Drop-in replacements for the Filament actions that perform a real write on
 * an audited general/master-data model (OMS Task 9B.2).
 *
 * Each factory returns the stock action with only its process closure
 * swapped via ->using(), so labels, confirmation modals, icons, success and
 * failure notifications, authorization, the trashed-record hiding rules and
 * the default post-delete redirect to the resource Index all stay exactly as
 * Filament configures them. The swapped closure performs the identical
 * mutation, inside AuditedCrudService's transaction.
 *
 * Only actions that actually write are wrapped. On a resource page a table's
 * CreateAction/EditAction/ViewAction are plain links to the Create/Edit/View
 * pages (Filament\Resources\Pages\Page::getDefaultActionUrl) and perform no
 * write at all — those paths are audited by the Create/Edit pages themselves
 * via AuditsRecordCreation/AuditsRecordUpdate. The modal-based
 * relationCreate()/edit() factories here exist for relation managers, which
 * have no related resource to link to and so really do write in place.
 */
final class AuditedActions
{
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->using(static fn (Model $record): bool => self::service()->delete($record));
    }

    /**
     * fetchSelectedRecords() is pinned on so the bulk path can never take
     * Filament's alternative branch of issuing one mass `$query->delete()`,
     * which would bypass the models — and therefore the audit trail —
     * entirely. Each record is deleted in its own transaction with its own
     * AuditEvent: one logical deletion, one event. If a record's REQUIRED
     * audit insert fails, only that record's deletion rolls back and
     * Filament reports it as a per-record failure, exactly as its own
     * default closure does for any other per-record exception.
     */
    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->fetchSelectedRecords()
            ->using(static function (DeleteBulkAction $action, Collection $records): void {
                $service = self::service();
                $isFirstException = true;

                $records->each(static function (Model $record) use ($action, $service, &$isFirstException): void {
                    try {
                        $service->delete($record) || $action->reportBulkProcessingFailure();
                    } catch (Throwable $exception) {
                        $action->reportBulkProcessingFailure();

                        if ($isFirstException) {
                            report($exception);

                            $isFirstException = false;
                        }
                    }
                });
            });
    }

    /**
     * Relation-manager create. The owning relationship performs the insert
     * so the parent foreign key is applied exactly as Filament's own
     * handler would.
     */
    public static function relationCreate(): CreateAction
    {
        return CreateAction::make()
            ->using(static function (array $data, ?Table $table): Model {
                $relationship = $table?->getRelationship();

                if (! $relationship instanceof Relation) {
                    throw new LogicException(
                        'AuditedActions::relationCreate() is only valid inside a relation manager.'
                    );
                }

                return self::service()->createViaRelationship($relationship, $data);
            });
    }

    public static function edit(): EditAction
    {
        return EditAction::make()
            ->using(static fn (array $data, Model $record): Model => self::service()->update($record, $data));
    }

    private static function service(): AuditedCrudService
    {
        return app(AuditedCrudService::class);
    }
}
