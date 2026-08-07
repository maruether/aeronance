<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PartTypes\Pages;

use App\Modules\Warehouse\Filament\Resources\PartTypes\PartTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditPartType extends EditRecord
{
    protected static string $resource = PartTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
