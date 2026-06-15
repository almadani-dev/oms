<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('معلومات عامة')->columns(2)->schema([
                TextEntry::make('code')
                    ->label('كود المشروع')
                    ->badge()
                    ->color('primary')
                    ->columnSpanFull(),
                TextEntry::make('name')
                    ->label('اسم المشروع')
                    ->columnSpanFull(),
                TextEntry::make('projectStatus.name')
                    ->label('الحالة'),
                TextEntry::make('donor.name')
                    ->label('الجهة المانحة'),
                TextEntry::make('projectSuper.name')
                    ->label('المشروع الرئيسي'),
                TextEntry::make('donor_project_name')
                    ->label('اسم المشروع لدى الجهة المانحة')
                    ->columnSpanFull(),
            ]),
            Section::make('التواريخ')->columns(2)->schema([
                TextEntry::make('approval_date')
                    ->label('تاريخ الاعتماد')
                    ->date(),
                TextEntry::make('implementation_date')
                    ->label('تاريخ التنفيذ')
                    ->date(),
                TextEntry::make('start_date')
                    ->label('تاريخ البداية')
                    ->date(),
                TextEntry::make('end_date')
                    ->label('تاريخ النهاية')
                    ->date(),
                TextEntry::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
