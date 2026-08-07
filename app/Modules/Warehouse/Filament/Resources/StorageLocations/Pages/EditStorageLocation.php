<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StorageLocations\Pages;

use App\Modules\Warehouse\Filament\Resources\StorageLocations\StorageLocationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditStorageLocation extends EditRecord
{
    protected static string $resource = StorageLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
