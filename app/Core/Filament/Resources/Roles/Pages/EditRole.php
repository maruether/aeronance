<?php

declare(strict_types=1);

namespace App\Core\Filament\Resources\Roles\Pages;

use App\Core\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\EditRecord;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;
}
