<?php

namespace App\Filament\Resources\ProjectStatuses;

use App\Filament\Resources\ProjectStatuses\Pages\CreateProjectStatus;
use App\Filament\Resources\ProjectStatuses\Pages\EditProjectStatus;
use App\Filament\Resources\ProjectStatuses\Pages\ListProjectStatuses;
use App\Filament\Resources\ProjectStatuses\Pages\ViewProjectStatus;
use App\Filament\Resources\ProjectStatuses\Schemas\ProjectStatusForm;
use App\Filament\Resources\ProjectStatuses\Tables\ProjectStatusesTable;
use App\Models\ProjectStatus;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectStatusResource extends Resource
{
    protected static ?string $model = ProjectStatus::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;
    protected static \UnitEnum|string|null $navigationGroup = 'الإعدادات';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'حالات المشاريع';
    protected static ?string $modelLabel = 'حالة مشروع';
    protected static ?string $pluralModelLabel = 'حالات المشاريع';
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProjectStatusForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectStatusesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProjectStatuses::route('/'),
            'create' => CreateProjectStatus::route('/create'),
            'view'   => ViewProjectStatus::route('/{record}'),
            'edit'   => EditProjectStatus::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
