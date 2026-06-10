<?php

namespace App\Filament\Resources\Attachments;

use App\Filament\Resources\Attachments\Pages\CreateAttachment;
use App\Filament\Resources\Attachments\Pages\EditAttachment;
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
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
            'index'  => ListAttachments::route('/'),
            'create' => CreateAttachment::route('/create'),
            'view'   => ViewAttachment::route('/{record}'),
            'edit'   => EditAttachment::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
