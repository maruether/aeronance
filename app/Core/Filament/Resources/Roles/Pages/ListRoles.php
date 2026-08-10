<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Roles\Pages;

use App\Core\Filament\Resources\Roles\RoleResource;
use App\Core\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                /*
                 * Selbst gebaut statt Vorgabe: Die Gruppenfelder des Formulars
                 * sind virtuell (siehe RoleForm), Role::create() wuerde sie fuer
                 * Spalten halten. guard_name ausdruecklich, weil die ganze
                 * Rechtepruefung am web-Guard haengt.
                 */
                ->using(function (array $data): Role {
                    $role = Role::create([
                        ...RoleForm::withoutPermissionFields($data),
                        'guard_name' => 'web',
                    ]);

                    $role->permissions()->sync(RoleForm::permissionIdsFrom($data));

                    app(PermissionRegistrar::class)->forgetCachedPermissions();

                    return $role;
                }),
        ];
    }
}
