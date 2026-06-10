<?php

namespace App\Filament\Resources\ProjectSupers;

use App\Filament\Resources\ProjectSupers\Pages\CreateProjectSuper;
use App\Filament\Resources\ProjectSupers\Pages\EditProjectSuper;
use App\Filament\Resources\ProjectSupers\Pages\ListProjectSupers;
use App\Filament\Resources\ProjectSupers\Pages\ViewProjectSuper;
use App\Filament\Resources\ProjectSupers\Schemas\ProjectSuperForm;
use App\Filament\Resources\ProjectSupers\Tables\ProjectSupersTable;
use App\Models\ProjectSuper;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProjectSuperResource extends Resource
{
    protected static ?string $model = ProjectSuper::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static \UnitEnum|string|null $navigationGroup = 'المشاريع';

    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'المشاريع الرئيسية';
    protected static ?string $modelLabel = 'مشروع رئيسي';
    protected static ?string $pluralModelLabel = 'المشاريع الرئيسية';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProjectSuperForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectSupersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProjectSupers::route('/'),
            'create' => CreateProjectSuper::route('/create'),
            'view'   => ViewProjectSuper::route('/{record}'),
            'edit'   => EditProjectSuper::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
