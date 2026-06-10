<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CostsRelationManager extends RelationManager
{
    protected static string $relationship = 'costs';

    protected static ?string $title = 'تكاليف المشروع';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('account_type_id')->relationship('accountType', 'name')->searchable()->preload()->required()->label('نوع الحساب'),
            TextInput::make('amount')->numeric()->required()->label('المبلغ'),
            TextInput::make('administrative_percentage')->numeric()->suffix('%')->default(0)->label('نسبة الإدارة %'),
            Textarea::make('notes')->label('ملاحظات'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('accountType.name')
            ->emptyStateHeading('لا توجد تكاليف')
            ->emptyStateDescription('قم بإضافة تكلفة للمشروع للبدء')
            ->columns([
                TextColumn::make('accountType.name')->label('نوع الحساب')->sortable(),
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('administrative_percentage')->label('نسبة الإدارة %')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('implementation_amount')->label('مبلغ التنفيذ')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
                TextColumn::make('received_amount')->label('المبلغ المستلم')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
