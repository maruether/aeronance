<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StorageLocations\Pages;

use App\Modules\Warehouse\Filament\Resources\StorageLocations\StorageLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStorageLocations extends ListRecords
{
    protected static string $resource = StorageLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
