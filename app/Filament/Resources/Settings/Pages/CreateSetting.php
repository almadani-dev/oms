<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Settings\SettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSetting extends CreateRecord
{
    use RedirectsToResourceView;

    protected static string $resource = SettingResource::class;
}
