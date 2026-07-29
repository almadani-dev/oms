<?php

namespace App\Filament\Resources\Currencies\RelationManagers;

use App\Filament\Concerns\AuditedActions;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExchangeRateHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'exchangeRateHistory';

    protected static ?string $title = 'تاريخ أسعار الصرف';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')->label('التاريخ')->required(),
            TextInput::make('rate')
                ->label('سعر الصرف')
                ->required()
                ->numeric()
                ->step(0.000001),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('date')
            ->columns([
                TextColumn::make('date')->label('التاريخ')->date()->sortable(),
                TextColumn::make('rate')->label('سعر الصرف')->formatStateUsing(fn($state) => \App\Helpers\NumberHelper::bigComma($state, 6))->html()->sortable(),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            // This relation manager really does write in place (there is no
            // related resource page to link to), so every write action here
            // must go through AuditedActions — OMS Task 9B.3. A rate created
            // or edited here produces exactly the same single
            // `exchange_rate_history` event as the standalone resource does.
            ->recordActions([
                AuditedActions::edit(),
                AuditedActions::delete(),
            ])
            ->headerActions([
                AuditedActions::relationCreate(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    AuditedActions::deleteBulk(),
                ]),
            ]);
    }
}
