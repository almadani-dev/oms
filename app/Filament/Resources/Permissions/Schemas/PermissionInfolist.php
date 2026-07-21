<?php

namespace App\Filament\Resources\Permissions\Schemas;

use App\Support\Permissions\PermissionRegistry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

class PermissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('name')
                    ->label('الاسم التقني')
                    ->badge()
                    ->color('primary')
                    ->copyable(),
                TextEntry::make('label')
                    ->label('الاسم بالعربية')
                    ->state(fn (Permission $record): string => PermissionRegistry::all()[$record->name] ?? $record->name),
                TextEntry::make('module')
                    ->label('الوحدة')
                    ->state(fn (Permission $record): string => self::moduleLabel($record->name)),
                TextEntry::make('guard_name')
                    ->label('الحارس'),
                TextEntry::make('status')
                    ->label('الحالة')
                    ->state(fn (Permission $record): string => self::isRegistered($record->name) ? 'مسجلة' : 'مخصصة')
                    ->badge()
                    ->color(fn (Permission $record): string => self::isRegistered($record->name) ? 'success' : 'warning'),
                TextEntry::make('roles')
                    ->label('الأدوار المستخدمة لهذه الصلاحية')
                    ->state(fn (Permission $record): string => $record->roles->pluck('name')->implode('، ') ?: 'لا يوجد')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label('تاريخ آخر تحديث')
                    ->dateTime(),
            ]),
        ]);
    }

    private static function isRegistered(string $name): bool
    {
        return in_array($name, PermissionRegistry::names(), true);
    }

    private static function moduleLabel(string $name): string
    {
        $module = PermissionRegistry::moduleForPermission($name);

        if ($module === null) {
            return 'صلاحيات مخصصة';
        }

        return PermissionRegistry::groups()[$module]['label'];
    }
}
