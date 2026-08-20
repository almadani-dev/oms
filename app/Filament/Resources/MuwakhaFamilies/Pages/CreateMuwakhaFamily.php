<?php

namespace App\Filament\Resources\MuwakhaFamilies\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\MuwakhaFamilies\MuwakhaFamilyResource;
use App\Services\Muwakha\MuwakhaFamilyService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Routes creation through MuwakhaFamilyService so the Account, the family and
 * both REQUIRED AuditEvents share one transaction.
 *
 * Deliberately does NOT use AuditsRecordCreation: that concern creates the one
 * model it is given, which would produce a family with no Account.
 */
class CreateMuwakhaFamily extends CreateRecord
{
    use RedirectsToResourceView;

    protected static string $resource = MuwakhaFamilyResource::class;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم إنشاء الأسرة وحسابها بنجاح';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(MuwakhaFamilyService::class)->create($data);
    }
}
