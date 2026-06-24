<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\Currency;
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
use Illuminate\Database\Eloquent\Builder;

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
            TextInput::make('amount')->numeric()->required()->label('المبلغ'),
            Select::make('currency_id')
                ->label('العملة')
                ->options(
                    Currency::orderBy('code')->get()
                        ->mapWithKeys(fn ($c) => [$c->id => $c->code . ' - ' . $c->name])
                )
                ->searchable()
                ->required(),
            Textarea::make('notes')->label('ملاحظات')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('currency'))
            ->recordTitleAttribute('amount')
            ->emptyStateHeading('لا توجد تكاليف')
            ->emptyStateDescription('قم بإضافة تكلفة للمشروع للبدء')
            ->columns([
                TextColumn::make('amount')->label('المبلغ')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state))->html()->sortable(),
                TextColumn::make('currency.name')->label('العملة')->sortable(),
                TextColumn::make('notes')->label('ملاحظات')->wrap(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
