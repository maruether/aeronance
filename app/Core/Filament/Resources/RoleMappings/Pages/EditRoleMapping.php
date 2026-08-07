<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\RoleMappings\Pages;

use App\Core\Filament\Resources\RoleMappings\RoleMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditRoleMapping extends EditRecord
{
    protected static string $resource = RoleMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
