<?php

namespace App\Filament\Resources\Attachments\Pages;

use App\Filament\Resources\Attachments\AttachmentResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No header actions: AttachmentResource::canEdit() is hard-false (see OMS
 * Task 6A hardening), so an EditAction would only ever link to a route
 * that no longer exists.
 */
class ViewAttachment extends ViewRecord
{
    protected static string $resource = AttachmentResource::class;
}
