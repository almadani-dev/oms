<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No header actions at all — there is nothing to create, import, export or
 * bulk-operate on here (OMS Task 9B.7). Access is enforced by
 * AuditEventResource::canViewAny() via Filament's own page authorization,
 * which is re-evaluated on navigation registration, on mount, and on every
 * subsequent Livewire hydration.
 *
 * Deliberately does NOT use App\Filament\Concerns\AuditsRecordCreation,
 * AuditsRecordUpdate or AuditedActions: opening, paginating, sorting,
 * filtering or searching the audit log must never write a new AuditEvent.
 */
class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
