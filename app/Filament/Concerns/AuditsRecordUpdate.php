<?php

namespace App\Filament\Concerns;

use App\Services\Audit\Crud\AuditedCrudService;
use Illuminate\Database\Eloquent\Model;

/**
 * Routes a full-page Edit save through AuditedCrudService (OMS Task 9B.2),
 * so the update and its REQUIRED AuditEvent share one transaction.
 *
 * Replaces Filament\Resources\Pages\EditRecord::handleRecordUpdate()
 * exactly — the same fill-then-save, so validation, form mutation hooks,
 * the saved notification and the RedirectsToResourceView redirect standard
 * are all untouched. A save in which no audited business field changed
 * still saves and simply produces no event.
 *
 * @mixin \Filament\Resources\Pages\EditRecord
 */
trait AuditsRecordUpdate
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(AuditedCrudService::class)->update($record, $data);
    }
}
