<?php

namespace App\Filament\Resources\Attachments;

use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\Attachments\Pages\ViewAttachment;
use App\Filament\Resources\Attachments\Schemas\AttachmentForm;
use App\Filament\Resources\Attachments\Tables\AttachmentsTable;
use App\Models\Attachment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Structurally read-only (OMS Task 6A hardening). Its own FileUpload
 * resolves to the pre-existing `local` disk (config('filesystems.default')
 * - see the Task 6A audit), which sits entirely outside
 * AttachmentController's authorization flow, so every mutation ability is
 * hard-overridden to `false` below instead of left to the Gate - mirroring
 * PermissionResource's pattern, for the same reason documented there:
 * `Gate::before` bypasses the underlying Policy for Super Admin, so a
 * Policy-only denial would not actually be enough to keep this closed.
 * Only `index`/`view` routes are registered (no Create/Edit page exists at
 * all - matches PermissionResource's convention of deleting the unused
 * page classes rather than leaving them unregistered).
 *
 * Hidden from navigation: the real database has zero rows through this
 * resource's own attachable types (Project/Transaction/Partner - see the
 * audit), so there is nothing an authorized user needs to reach here today.
 *
 * attachments.* permissions and AttachmentPolicy are untouched -
 * viewAny/view still work exactly as before for whoever is granted them.
 */
class AttachmentResource extends Resource
{
    protected static ?string $model = Attachment::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;
    protected static \UnitEnum|string|null $navigationGroup = 'النظام';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'المرفقات';
    protected static ?string $modelLabel = 'مرفق';
    protected static ?string $pluralModelLabel = 'المرفقات';
    protected static ?string $recordTitleAttribute = 'file_name';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return AttachmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttachmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttachments::route('/'),
            'view' => ViewAttachment::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}
