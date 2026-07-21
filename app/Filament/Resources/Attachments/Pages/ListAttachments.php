<?php

namespace App\Filament\Resources\Attachments\Pages;

use App\Filament\Resources\Attachments\AttachmentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No header actions: AttachmentResource::canCreate() is hard-false (see
 * OMS Task 6A hardening), so a CreateAction would only ever link to a
 * route that no longer exists.
 */
class ListAttachments extends ListRecords
{
    protected static string $resource = AttachmentResource::class;
}
