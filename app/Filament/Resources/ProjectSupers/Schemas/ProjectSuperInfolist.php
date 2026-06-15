<?php

namespace App\Filament\Resources\ProjectSupers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectSuperInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('code')
                    ->label('كود المشروع الرئيسي')
                    ->badge()
                    ->color('primary')
                    ->columnSpanFull(),
                TextEntry::make('code_prefix')
                    ->label('بادئة الكود'),
                TextEntry::make('name')
                    ->label('اسم المشروع الرئيسي'),
                TextEntry::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
