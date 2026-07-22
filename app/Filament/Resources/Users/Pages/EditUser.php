<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\RedirectsToResourceView;
use App\Filament\Resources\Users\UserResource;
use App\Services\Users\UserManagementService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    use RedirectsToResourceView;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roles'] = $this->record->roles()->pluck('name')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(UserManagementService::class)->updateUser(auth()->user(), $record, $data);
    }

    protected function getHeaderActions(): array
    {
        $service = app(UserManagementService::class);

        return [
            DeleteAction::make()
                ->visible(fn (Model $record): bool => ! $record->is(auth()->user()) && ! $service->isLastActiveSuperAdmin($record))
                ->action(static function (Model $record, Action $action) use ($service): void {
                    $service->deleteUser(auth()->user(), $record);
                    $action->success();
                }),
        ];
    }
}
