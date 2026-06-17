<?php

namespace App\Filament\Resources\ProjectCosts\Schemas;

use App\Models\Currency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectCostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('project_id')
                    ->label('المشروع')
                    ->relationship('project', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code ?: $record->name)
                    ->searchable()
                    ->preload(false)
                    ->optionsLimit(50)
                    ->required()
                    ->columnSpanFull(),
                Select::make('account_type_id')
                    ->label('نوع الحساب')
                    ->relationship('accountType', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('currency_id')
                    ->label('العملة')
                    ->options(
                        Currency::orderBy('code')->get(['id', 'code', 'name'])
                            ->mapWithKeys(fn ($c) => [$c->id => $c->code . ' - ' . $c->name])
                    )
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required(),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}