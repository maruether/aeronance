<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Roles\Pages;

use App\Core\Filament\Resources\Roles\RoleResource;
use App\Core\Filament\Resources\Roles\Schemas\RoleForm;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->getRecord();

        // Die Gruppenfelder sind virtuell (siehe RoleForm) -- die Rolle selbst
        // kennt sie nicht, also werden sie hier aus der Relation gefuellt.
        return $data + RoleForm::permissionStateFor($role);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        $record->update(RoleForm::withoutPermissionFields($data));

        // EIN sync ueber die Vereinigung aller Gruppen. Je Gruppe zu syncen
        // wuerde die Haken der jeweils anderen wieder austragen.
        $record->permissions()->sync(RoleForm::permissionIdsFrom($data));

        // Spatie cacht die Rechte aggressiv; ohne das hier gilt die Aenderung
        // erst, wenn der Cache von selbst ablaeuft.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $record;
    }
}
