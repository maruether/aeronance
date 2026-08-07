<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PartTypes\Pages;

use App\Modules\Warehouse\Filament\Resources\PartTypes\PartTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartTypes extends ListRecords
{
    protected static string $resource = PartTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
