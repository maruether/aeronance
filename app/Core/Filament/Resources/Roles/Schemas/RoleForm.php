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
use Spatie\Permission\Models\Role;

/**
 * Editing a role.
 *
 * Permissions are grouped the way their owner declared them -- core first, then
 * one block per active module -- because a flat list of thirty verbs is a wall
 * nobody reads.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * JEDE GRUPPE IST EIN EIGENES FELD, und die Seite fuegt sie beim Speichern
 * zusammen. Vorher hiessen ALLE Listen "permissions": Ihre Zustaende
 * ueberschrieben einander, validiert wurde gegen die Optionen der LETZTEN
 * Gruppe -- ein Haken in irgendeiner anderen scheiterte mit "validation.in".
 * Gemessen auf test.aeronance.de: Ein Administrator konnte keine einzige
 * Berechtigung vergeben.
 *
 * Und ->relationship() haette es auch nach einer Umbenennung nicht getan:
 * Jede Liste synct die GANZE Relation -- die letzte haette die Haken aller
 * anderen Gruppen wieder ausgetragen. Deshalb sind die Felder virtuell, und
 * EditRole/ListRoles vereinigen sie zu EINEM sync. Siehe permissionIdsFrom().
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class RoleForm
{
    private const FIELD_PREFIX = 'permissions__';

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
                    CheckboxList::make(self::fieldFor($group))
                        ->hiddenLabel()
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
     * Der Formularzustand einer Rolle: je Gruppe die gehaltenen Rechte.
     *
     * @return array<string, list<int>>
     */
    public static function permissionStateFor(Role $role): array
    {
        $held = $role->permissions()->pluck('id')->all();
        $state = [];

        foreach (app(PermissionRegistry::class)->grouped() as $group => $definitions) {
            $names = array_map(static fn (PermissionDefinition $d): string => $d->name, $definitions);

            $state[self::fieldFor($group)] = Permission::query()
                ->whereIn('name', $names)
                ->whereIn('id', $held)
                ->pluck('id')
                ->all();
        }

        return $state;
    }

    /**
     * Alle Gruppenfelder zu EINER Liste von Rechte-IDs vereinigt.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function permissionIdsFrom(array $data): array
    {
        $ids = [];

        foreach ($data as $field => $value) {
            if (str_starts_with($field, self::FIELD_PREFIX) && is_array($value)) {
                $ids = [...$ids, ...array_map(intval(...), $value)];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Die Daten ohne die virtuellen Gruppenfelder -- was uebrig bleibt, sind
     * echte Spalten der Rolle.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutPermissionFields(array $data): array
    {
        return array_filter(
            $data,
            static fn (string $field): bool => ! str_starts_with($field, self::FIELD_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function fieldFor(string $group): string
    {
        // Punkte sind in Formularnamen Pfadtrenner ("part66.logs" wuerde ein
        // verschachteltes Feld ergeben, das nie ankommt) -- wie in SettingsPage.
        return self::FIELD_PREFIX.str_replace('.', '__', $group);
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
