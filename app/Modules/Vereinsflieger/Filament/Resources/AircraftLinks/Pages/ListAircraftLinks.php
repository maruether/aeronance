<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Filament\Resources\AircraftLinks\Pages;

use App\Modules\Vereinsflieger\Filament\Resources\AircraftLinks\AircraftLinkResource;
use Filament\Resources\Pages\ListRecords;

final class ListAircraftLinks extends ListRecords
{
    protected static string $resource = AircraftLinkResource::class;
}
