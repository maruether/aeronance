<?php

declare(strict_types=1);

namespace App\Modules\Vereinsflieger\Filament\Resources\Connections\Pages;

use App\Modules\Vereinsflieger\Filament\Resources\Connections\ConnectionResource;
use Filament\Resources\Pages\ListRecords;

final class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionResource::class;
}
