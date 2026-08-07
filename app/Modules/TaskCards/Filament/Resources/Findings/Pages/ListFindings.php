<?php

declare(strict_types=1);

namespace App\Modules\TaskCards\Filament\Resources\Findings\Pages;

use App\Modules\TaskCards\Filament\Resources\Findings\FindingResource;
use Filament\Resources\Pages\ListRecords;

final class ListFindings extends ListRecords
{
    protected static string $resource = FindingResource::class;
}
