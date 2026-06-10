<?php

namespace App\Filament\Resources\Attachments\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttachmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('attachable_type')
                    ->label('متعلق بـ')
                    ->options([
                        'App\Models\Project'     => 'مشروع',
                        'App\Models\Transaction' => 'معاملة مالية',
                        'App\Models\Partner'     => 'شريك',
                    ])
                    ->required(),
                TextInput::make('attachable_id')
                    ->label('رقم السجل')
                    ->numeric()
                    ->required(),
                FileUpload::make('file_path')
                    ->label('الملف')
                    ->required()
                    ->preserveFilenames()
                    ->columnSpanFull(),
                TextInput::make('file_name')
                    ->label('اسم الملف')
                    ->maxLength(255),
                TextInput::make('file_type')
                    ->label('نوع الملف')
                    ->maxLength(100),
                TextInput::make('file_size')
                    ->label('حجم الملف (بايت)')
                    ->numeric(),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
        ]);
    }
}