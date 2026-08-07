<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\RoleMappings\Pages;

use App\Core\Filament\Resources\RoleMappings\RoleMappingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateRoleMapping extends CreateRecord
{
    protected static string $resource = RoleMappingResource::class;
}
