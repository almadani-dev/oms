<?php

namespace App\Filament\Resources\MuwakhaFamilies\Pages;

use App\Filament\Resources\MuwakhaFamilies\MuwakhaFamilyResource;
use App\Models\MuwakhaFamily;
use App\Services\Muwakha\MuwakhaFamilyAccountStatementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMuwakhaFamily extends ViewRecord
{
    protected static string $resource = MuwakhaFamilyResource::class;

    /**
     * "كشف حساب الأسرة" is the ONLY entry point to the family's financial
     * statement — there is no sidebar item and no family selector, so the
     * report's subject is always the record this page is already showing.
     *
     * The action is a plain link to the resource page; every authorization
     * decision is made server-side on that page (on mount and on every
     * Livewire hydration). Hiding it here only keeps the button out of a
     * Viewer-without-view's way; it is not the guard.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('accountStatement')
                ->label(MuwakhaFamilyAccountStatementService::TITLE)
                ->icon('heroicon-o-document-currency-dollar')
                ->color('gray')
                ->url(fn (MuwakhaFamily $record): string => MuwakhaFamilyResource::getUrl('account-statement', [
                    'record' => $record,
                ]))
                ->visible(fn (MuwakhaFamily $record): bool => MuwakhaFamilyAccountStatement::canAccess([
                    'record' => $record,
                ])),

            EditAction::make(),
        ];
    }
}
