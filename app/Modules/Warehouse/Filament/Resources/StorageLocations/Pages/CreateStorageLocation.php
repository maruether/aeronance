<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StorageLocations\Pages;

use App\Modules\Warehouse\Filament\Resources\StorageLocations\StorageLocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStorageLocation extends CreateRecord
{
    protected static string $resource = StorageLocationResource::class;
}
