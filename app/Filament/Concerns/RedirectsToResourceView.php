<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * OMS-wide CRUD redirect standard for full-page Create / Edit operations.
 *
 * Business rule: after a successful full-page Create or Edit, the user is
 * redirected to the saved record's View page. The destination URL is always
 * resolved through the Resource (never a hard-coded panel path), so the
 * current panel, tenant and route context are preserved automatically.
 *
 * Safety: if the acting user is not authorized to View the record — or the
 * resource has no View page — this falls back to the resource Index page
 * instead of sending the user to a URL that would predictably return 403.
 * It never grants, weakens or bypasses any authorization: the View page's own
 * policy still runs on arrival.
 *
 * Delete is intentionally NOT handled here. Filament already redirects a
 * page-level DeleteAction / ForceDeleteAction to the resource Index via
 * \Filament\Resources\Pages\Concerns\InteractsWithRecord::getDefaultActionSuccessRedirectUrl(),
 * which already matches the required standard, so no override is added for it.
 *
 * @mixin \Filament\Resources\Pages\CreateRecord
 * @mixin \Filament\Resources\Pages\EditRecord
 */
trait RedirectsToResourceView
{
    protected function getRedirectUrl(): string
    {
        $resource = static::getResource();
        $record = $this->getRecord();

        if ($record instanceof Model && $resource::hasPage('view') && $resource::canView($record)) {
            return $resource::getUrl('view', ['record' => $record]);
        }

        return $resource::getUrl('index');
    }
}
