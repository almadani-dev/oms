<?php

namespace App\Filament\Concerns;

use App\Services\Audit\Crud\AuditedCrudService;
use Illuminate\Database\Eloquent\Model;

/**
 * Routes a full-page Create through AuditedCrudService (OMS Task 9B.2), so
 * the insert and its REQUIRED AuditEvent share one transaction.
 *
 * Replaces Filament\Resources\Pages\CreateRecord::handleRecordCreation()
 * exactly — same construction, same parent-resource association — and
 * nothing else: form mutation hooks, notifications and the
 * RedirectsToResourceView redirect standard all still run unchanged.
 *
 * @mixin \Filament\Resources\Pages\CreateRecord
 */
trait AuditsRecordCreation
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $service = app(AuditedCrudService::class);

        if ($parentRecord = $this->getParentRecord()) {
            return $service->createViaRelationship(
                static::getResource()::getParentResourceRegistration()->getRelationship($parentRecord),
                $data,
            );
        }

        return $service->create(new ($this->getModel()), $data);
    }
}
