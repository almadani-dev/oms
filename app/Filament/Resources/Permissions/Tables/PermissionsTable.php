<?php

namespace App\Filament\Resources\Permissions\Tables;

use App\Support\Permissions\PermissionRegistry;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;

/**
 * Read-only by construction: only `ViewAction` is registered — no
 * Edit/Delete, no bulk actions (never calling `->toolbarActions()`/
 * `->bulkActions()` means Filament never renders row-selection checkboxes),
 * no force-delete, no restore. `PermissionResource`'s hard `canX()`
 * overrides are what actually block mutation even for Super Admin; this
 * table simply never offers a mutation action in the first place.
 *
 * A DB `Permission` row outside `PermissionRegistry` (legacy/custom) is
 * never filtered out — it falls back to its technical name as the Arabic
 * label and to the "صلاحيات مخصصة" module, and is flagged "مخصصة" by the
 * status column/filter.
 */
class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('roles')->with('roles'))
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم التقني')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('label')
                    ->label('الاسم بالعربية')
                    ->state(fn (Permission $record): string => PermissionRegistry::all()[$record->name] ?? $record->name)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $matchingNames = collect(PermissionRegistry::all())
                            ->filter(fn (string $label): bool => str_contains($label, $search))
                            ->keys()
                            ->all();

                        return $query->when(
                            $matchingNames !== [],
                            fn (Builder $q) => $q->orWhereIn('name', $matchingNames),
                        );
                    }),
                TextColumn::make('module')
                    ->label('الوحدة')
                    ->state(fn (Permission $record): string => self::moduleLabel($record->name))
                    ->badge(),
                TextColumn::make('guard_name')
                    ->label('الحارس')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('roles_count')
                    ->label('عدد الأدوار')
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('الأدوار')
                    ->badge()
                    ->searchable()
                    ->limitList(3)
                    ->expandableLimitedList(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->state(fn (Permission $record): string => self::isRegistered($record->name) ? 'مسجلة' : 'مخصصة')
                    ->badge()
                    ->color(fn (Permission $record): string => self::isRegistered($record->name) ? 'success' : 'warning'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('module')
                    ->label('الوحدة')
                    ->options(self::moduleFilterOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $module = $data['value'] ?? null;

                        if ($module === null || $module === '') {
                            return $query;
                        }

                        if ($module === 'custom') {
                            return $query->whereNotIn('name', PermissionRegistry::names());
                        }

                        $groups = PermissionRegistry::groups();
                        $permissionNames = array_keys($groups[$module]['permissions'] ?? []);

                        return $query->whereIn('name', $permissionNames);
                    }),
                SelectFilter::make('guard_name')
                    ->label('الحارس')
                    ->options(fn (): array => Permission::query()->distinct()->pluck('guard_name', 'guard_name')->all()),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(['registered' => 'مسجلة', 'custom' => 'مخصصة'])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        return match ($status) {
                            'registered' => $query->whereIn('name', PermissionRegistry::names()),
                            'custom' => $query->whereNotIn('name', PermissionRegistry::names()),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
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

    /**
     * @return array<string,string>
     */
    private static function moduleFilterOptions(): array
    {
        $options = [];

        foreach (PermissionRegistry::groups() as $module => $group) {
            if ($group['permissions'] === []) {
                continue;
            }

            $options[$module] = $group['label'];
        }

        $options['custom'] = 'صلاحيات مخصصة';

        return $options;
    }
}
