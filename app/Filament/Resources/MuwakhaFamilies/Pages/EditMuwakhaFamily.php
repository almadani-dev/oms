<?php

namespace App\Filament\Resources\MuwakhaFamilies\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\MuwakhaFamilies\MuwakhaFamilyResource;
use App\Filament\Resources\MuwakhaFamilies\Tables\MuwakhaFamiliesTable;
use App\Models\MuwakhaFamily;
use App\Services\Muwakha\MuwakhaFamilyService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Routes the save through MuwakhaFamilyService so the family row and its
 * Account are synchronized inside one transaction, and hydrates the payment
 * fields from the linked Account (they are not columns on this model).
 *
 * The header Delete action calls the same MuwakhaFamiliesTable::deleteAction()
 * the list uses, so a deletion means the same thing from either entry point.
 */
class EditMuwakhaFamily extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = MuwakhaFamilyResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return 'تم تعديل الأسرة وحسابها بنجاح';
    }

    protected function getHeaderActions(): array
    {
        return [
            MuwakhaFamiliesTable::deleteAction()
                ->successRedirectUrl($this->getResource()::getUrl('index')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MuwakhaFamily $record */
        $record = $this->getRecord();

        $account = $record->account;

        $data['account_code'] = $account?->account_code;
        $data['bank_type_id'] = $account?->bank_type_id;
        $data['iban'] = $account?->iban;

        // The Currency Select shows the currency of the CURRENTLY linked
        // Account. Submitting any different material identity does not rewrite
        // that Account — MuwakhaFamilyService either reuses an Account this
        // family already owns with exactly that identity, or creates a new one.
        $data['currency_id'] = $account?->currency_id;

        // The holder name that belongs to the CURRENT Account, taken from its
        // ownership mapping; the family column is the fallback for a record
        // written before mappings existed.
        $data['account_holder_name'] = $record->currentFamilyAccount()?->account_holder_name
            ?? $record->account_holder_name;

        // Read-only mirror, dehydrated(false) on the form so it is never
        // submitted back.
        $data['muwakha_account_type_display'] = $account?->accountType?->name;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MuwakhaFamily $record */
        return app(MuwakhaFamilyService::class)->update($record, $data);
    }
}
