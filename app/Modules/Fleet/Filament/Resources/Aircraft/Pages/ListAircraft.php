<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Aircraft\Pages;

use App\Modules\Fleet\Filament\Resources\Aircraft\AircraftResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAircraft extends ListRecords
{
    protected static string $resource = AircraftResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
