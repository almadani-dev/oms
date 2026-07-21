<?php

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Resources\Permissions\PermissionResource;
use App\Models\User;
use App\Services\Permissions\PermissionManagementService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * The only header action is the protected synchronization action. Hiding it
 * via `->visible()` is defense layer 1 only — `PermissionManagementService::
 * sync()` re-authorizes (authenticated actor + exact `Super Admin` role +
 * `permissions.sync`) on every invocation, including a crafted/direct
 * Livewire `callAction()` call, and throws before touching the database on
 * failure (see PermissionSyncActionTest).
 */
class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncPermissions')
                ->label('مزامنة الصلاحيات')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => $this->canSync())
                ->requiresConfirmation()
                ->modalHeading('مزامنة الصلاحيات')
                ->modalDescription('سيتم إنشاء الصلاحيات المسجلة الناقصة ومزامنة صلاحيات الأدوار الخمسة الافتراضية فقط. لن يتم حذف أي صلاحية أو دور أو تعديل الأدوار المخصصة.')
                ->modalSubmitActionLabel('تأكيد المزامنة')
                ->action(function (): void {
                    /** @var User|null $actor */
                    $actor = auth()->user();

                    $result = app(PermissionManagementService::class)->sync($actor);

                    Notification::make()
                        ->title('تمت مزامنة الصلاحيات بنجاح')
                        ->body(sprintf(
                            'الصلاحيات الجديدة: %d — الصلاحيات الموجودة مسبقاً: %d — أدوار النظام الجديدة: %d — أدوار النظام الموجودة: %d.',
                            $result['permissions_created'],
                            $result['permissions_found'],
                            $result['roles_created'],
                            $result['roles_found'],
                        ))
                        ->success()
                        ->send();
                }),
        ];
    }

    private function canSync(): bool
    {
        /** @var User|null $actor */
        $actor = auth()->user();

        return $actor !== null && app(PermissionManagementService::class)->canSync($actor);
    }
}
