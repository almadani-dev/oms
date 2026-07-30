<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions at all — unlike ViewRole/ViewUser there is no Edit action
 * to offer (AuditEventResource::canEdit() is hard-false and no edit page
 * exists), and no delete/replicate/restore action either (OMS Task 9B.7).
 *
 * Like ListAuditEvents, this page never touches AuditLogger: viewing an audit
 * event must never create one.
 */
class ViewAuditEvent extends ViewRecord
{
    protected static string $resource = AuditEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
