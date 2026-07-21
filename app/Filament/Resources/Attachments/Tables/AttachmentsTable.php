<?php

namespace App\Filament\Resources\Attachments\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * Read-only by construction (OMS Task 6A hardening): only `ViewAction` is
 * registered - no Edit, no bulk actions (never calling `->toolbarActions()`
 * means Filament never renders row-selection checkboxes or a bulk-delete
 * button). AttachmentResource's hard `canX()` overrides are what actually
 * block mutation even for Super Admin; this table simply never offers a
 * mutation action in the first place - same split responsibility as
 * PermissionsTable/PermissionResource.
 */
class AttachmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('file_name')->label('اسم الملف')->searchable()->sortable(),
                TextColumn::make('file_type')->label('نوع الملف')->badge()->searchable(),
                TextColumn::make('attachable_type')->label('متعلق بـ')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'App\Models\Project'     => 'مشروع',
                        'App\Models\Transaction' => 'معاملة مالية',
                        'App\Models\Partner'     => 'شريك',
                        default                  => class_basename($state),
                    })
                    ->badge(),
                TextColumn::make('attachable_id')->label('رقم السجل'),
                TextColumn::make('file_size')->label('الحجم')->formatStateUsing(fn (?int $state): string => $state ? number_format($state / 1024, 1) . ' KB' : '-'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('attachable_type')
                    ->options([
                        'App\Models\Project'     => 'مشروع',
                        'App\Models\Transaction' => 'معاملة مالية',
                        'App\Models\Partner'     => 'شريك',
                    ])
                    ->label('متعلق بـ'),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
