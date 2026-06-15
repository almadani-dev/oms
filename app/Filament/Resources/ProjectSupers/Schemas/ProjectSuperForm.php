<?php

namespace App\Filament\Resources\ProjectSupers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectSuperForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->label('اسم المشروع الرئيسي')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('code_prefix')
                    ->label('بادئة الكود (مثال: WATER)')
                    ->required()
                    ->maxLength(20)
                    ->hint('سيتم إنشاء الكود تلقائياً')
                    ->dehydrateStateUsing(fn ($state) => strtoupper($state ?? '')),

                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
