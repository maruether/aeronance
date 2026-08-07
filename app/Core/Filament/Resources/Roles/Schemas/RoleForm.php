<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Roles\Schemas;

use App\Core\Access\PermissionDefinition;
use App\Core\Access\PermissionRegistry;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Editing a role.
 *
 * Permissions are grouped the way their owner declared them -- core first, then
 * one block per active module -- because a flat list of thirty verbs is a wall
 * nobody reads.
 */
final class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        $sections = [
            TextInput::make('name')
                ->label(__('roles.field.name'))
                ->required()
                ->maxLength(128)
                ->unique(ignoreRecord: true)
                ->helperText(__('roles.help.name')),
        ];

        foreach (app(PermissionRegistry::class)->grouped() as $group => $definitions) {
            $sections[] = Section::make(__('permissions.group.'.$group))
                ->schema([
                    CheckboxList::make('permissions')
                        ->hiddenLabel()
                        ->relationship('permissions', 'name')
                        ->options(self::optionsFor($definitions))
                        ->descriptions(self::descriptionsFor($definitions))
                        ->columns(2)
                        ->bulkToggleable(),
                ])
                ->collapsible();
        }

        return $schema->components($sections);
    }

    /**
     * @param  list<PermissionDefinition>  $definitions
     * @return array<int|string, string>
     */
    private static function optionsFor(array $definitions): array
    {
        $names = array_map(static fn (PermissionDefinition $d): string => $d->name, $definitions);

        // Looked up in PHP rather than through dot notation: permission names
        // contain dots, and "stock.quarantine" is both a permission in its own
        // right and the prefix of "stock.quarantine.certify".
        $labels = (array) trans('permissions.label');

        return Permission::query()
            ->whereIn('name', $names)
            ->pluck('name', 'id')
            ->map(fn (string $name): string => $labels[$name] ?? $name)
            ->all();
    }

    /**
     * @param  list<PermissionDefinition>  $definitions
     * @return array<int|string, string>
     */
    private static function descriptionsFor(array $definitions): array
    {
        $names = array_map(static fn (PermissionDefinition $d): string => $d->name, $definitions);
        $hints = (array) trans('permissions.hint');

        return Permission::query()
            ->whereIn('name', $names)
            ->pluck('name', 'id')
            ->mapWithKeys(function (string $name, int $id) use ($hints): array {
                // A permission without an explanatory line simply gets none.
                return [$id => $hints[$name] ?? ''];
            })
            ->filter()
            ->all();
    }
}
