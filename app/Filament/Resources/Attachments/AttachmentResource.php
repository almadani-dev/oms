<?php

namespace App\Filament\Resources\Attachments;

use App\Filament\Resources\Attachments\Pages\ListAttachments;
use App\Filament\Resources\Attachments\Pages\ViewAttachment;
use App\Filament\Resources\Attachments\Tables\AttachmentsTable;
use App\Models\Attachment;
use App\Services\Attachments\FinancialAttachmentRegistry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Secure, read-only financial attachment registry (OMS Task 6D).
 *
 * Re-enabled in the sidebar (النظام → سجل المرفقات) as a browse/search/view
 * surface over the five supported financial attachable types only. It never
 * uploads, creates, edits, deletes, restores, or force-deletes an Attachment
 * - every mutation ability stays hard-overridden to false (kept from the Task
 * 6A hardening, so a Policy-only denial that Gate::before would bypass for
 * Super Admin is never relied upon), only index/view routes exist, and no
 * FileUpload/Create/Edit page class exists at all.
 *
 * Authorization is two-layered:
 *  - opening the registry requires attachments.view_any (the AttachmentPolicy,
 *    unchanged);
 *  - every listed or viewed row must additionally pass the parent-module
 *    .view permission for its attachable type. The list query is scoped to the
 *    actor's allowed types (AttachmentsTable), and canView() re-enforces the
 *    same rule on the View page (a direct URL to a record the actor may not
 *    view on its parent returns 403, not merely a hidden row). Serving the
 *    bytes stays behind the existing attachments.show route and its parent
 *    Policy check - this registry never exposes a raw path, disk, or URL.
 *
 * The attachable-type => permission mapping and the supported-type list live
 * in FinancialAttachmentRegistry (a focused service), not inline here.
 */
class AttachmentResource extends Resource
{
    protected static ?string $model = Attachment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    protected static \UnitEnum|string|null $navigationGroup = 'النظام';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'سجل المرفقات';

    protected static ?string $modelLabel = 'مرفق';

    protected static ?string $pluralModelLabel = 'سجل المرفقات';

    protected static ?string $recordTitleAttribute = 'file_name';

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

    /**
     * Base query for both the table and record-route binding: restricted to
     * the five supported attachable types (so unsupported Project/Transaction/
     * Partner rows are never listed or bound here) and eager-loaded to avoid
     * N+1 across the polymorphic parent. Per-user permission scoping is added
     * on top only for the list table (AttachmentsTable) - deliberately NOT
     * here, so a direct View URL to a supported-but-unauthorized record still
     * resolves the record and returns 403 via canView(), rather than 404.
     */
    public static function getEloquentQuery(): Builder
    {
        return FinancialAttachmentRegistry::eagerLoad(
            FinancialAttachmentRegistry::scopeSupported(parent::getEloquentQuery()),
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * Opening the registry requires attachments.view_any (delegated to
     * AttachmentPolicy::viewAny via the default implementation).
     */

    /**
     * A record is viewable only when the actor holds attachments.view AND the
     * parent-module permission for its attachable type. Also gates the table's
     * ViewAction visibility, so a crafted action call cannot open a row the
     * actor may not view. Super Admin passes for every supported type via
     * Gate::before.
     */
    public static function canView(Model $record): bool
    {
        return FinancialAttachmentRegistry::userCanView(auth()->user(), $record);
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
