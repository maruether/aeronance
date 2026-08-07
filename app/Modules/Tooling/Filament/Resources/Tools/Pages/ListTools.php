<?php

declare(strict_types=1);

namespace App\Modules\Tooling\Filament\Resources\Tools\Pages;

use App\Modules\Tooling\Filament\Resources\Tools\ToolResource;
use Filament\Resources\Pages\ListRecords;

final class ListTools extends ListRecords
{
    protected static string $resource = ToolResource::class;
}
