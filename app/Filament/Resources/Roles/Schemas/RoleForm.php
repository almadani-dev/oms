<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Services\Roles\RoleManagementService;
use App\Support\Permissions\PermissionRegistry;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions are rendered as one collapsible Section per PermissionRegistry
 * module (plus a "صلاحيات مخصصة" section for any existing Permission row
 * outside the registry), each a CheckboxList bound to `permissions.{module}`
 * — flattened back into a single permission-name list by the Create/Edit
 * pages before it ever reaches RoleManagementService, which is the only
 * place a submission is authoritative. Deliberately not a
 * `->relationship()`-backed field: that would sync permissions directly
 * from the form, bypassing every RoleManagementService safety check.
 */
class RoleForm
{
    /**
     * The record's current permission names grouped by PermissionRegistry
     * module (plus a `custom` key for anything outside the registry) — the
     * shape `permissions.{module}` CheckboxLists expect when hydrating an
     * existing record on the View/Edit pages.
     *
     * @return array<string, list<string>>
     */
    public static function groupedPermissionNames(Role $role): array
    {
        $currentNames = $role->permissions()->pluck('name')->all();

        $grouped = [];

        foreach (PermissionRegistry::groups() as $module => $group) {
            $grouped[$module] = array_values(array_intersect($currentNames, array_keys($group['permissions'])));
        }

        $grouped['custom'] = array_values(array_diff($currentNames, PermissionRegistry::names()));

        return $grouped;
    }

    public static function configure(Schema $schema): Schema
    {
        $service = app(RoleManagementService::class);
        $actor = auth()->user();
        $assignableNames = $actor ? $service->assignablePermissionNames($actor) : [];

        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')
                    ->label('اسم الدور')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn (?Role $record): bool => $record !== null && $service->isSystemRole($record)),
            ]),
            ...static::permissionSections($assignableNames, $service),
        ]);
    }

    /**
     * @param  list<string>  $assignableNames
     * @return list<Section>
     */
    private static function permissionSections(array $assignableNames, RoleManagementService $service): array
    {
        $groups = PermissionRegistry::groups();

        $guard = (string) config('auth.defaults.guard', 'web');
        $customPermissionNames = Permission::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', PermissionRegistry::names())
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($customPermissionNames !== []) {
            $groups['custom'] = [
                'label' => 'صلاحيات مخصصة',
                'permissions' => array_combine($customPermissionNames, $customPermissionNames),
            ];
        }

        return collect($groups)
            ->map(function (array $group, string $module) use ($assignableNames, $service): Section {
                $groupPermissionNames = array_keys($group['permissions']);

                return Section::make($group['label'])
                    ->collapsible()
                    ->schema([
                        CheckboxList::make("permissions.{$module}")
                            ->hiddenLabel()
                            ->options(function (?Role $record) use ($group, $groupPermissionNames, $assignableNames): array {
                                $currentNames = $record
                                    ? $record->permissions->pluck('name')->intersect($groupPermissionNames)->all()
                                    : [];

                                $availableNames = array_unique(array_merge(
                                    array_intersect($assignableNames, $groupPermissionNames),
                                    $currentNames,
                                ));

                                return array_intersect_key($group['permissions'], array_flip($availableNames));
                            })
                            ->descriptions(array_combine($groupPermissionNames, $groupPermissionNames))
                            ->columns(2)
                            ->searchable()
                            ->bulkToggleable()
                            ->disabled(fn (?Role $record): bool => $record !== null && $service->isSystemRole($record)),
                    ]);
            })
            ->values()
            ->all();
    }
}
