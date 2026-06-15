<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('معلومات عامة')->columns(2)->schema([
                TextInput::make('name')
                    ->label('اسم المشروع')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('code')
                    ->label('كود المشروع')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('سيتم إنشاؤه تلقائياً بعد الحفظ')
                    ->columnSpanFull(),

                Select::make('project_status_id')
                    ->label('الحالة')
                    ->relationship('projectStatus', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('donor_id')
                    ->label('الجهة المانحة')
                    ->relationship('donor', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('project_super_id')
                    ->label('المشروع الرئيسي')
                    ->relationship('projectSuper', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code ?: $record->name)
                    ->searchable()
                    ->preload(),
                TextInput::make('donor_project_name')
                    ->label('اسم المشروع لدى الجهة المانحة')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]),
            Section::make('التواريخ')->columns(2)->schema([
                DatePicker::make('approval_date')
                    ->label('تاريخ الاعتماد'),
                DatePicker::make('implementation_date')
                    ->label('تاريخ التنفيذ'),
                DatePicker::make('start_date')
                    ->label('تاريخ البداية'),
                DatePicker::make('end_date')
                    ->label('تاريخ النهاية'),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull(),
            ]),
            Section::make('تكاليف المشروع')->columnSpanFull()->schema([
                Repeater::make('costs')
                    ->relationship()
                    ->label('')
                    ->schema([
                        Select::make('account_type_id')
                            ->label('نوع الحساب')
                            ->relationship('accountType', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpan(2),
                        Select::make('currency_id')
                            ->label('العملة')
                            ->options(
                                Currency::orderBy('code')->get()
                                    ->mapWithKeys(fn ($c) => [$c->id => $c->code . ' - ' . $c->name])
                            )
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required(),
                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->addActionLabel('إضافة تكلفة')
                    ->defaultItems(0)
                    ->collapsible()
                    ->reorderable(),
            ]),
        ]);
    }
}
