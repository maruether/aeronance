<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\RepairDispatches\Pages;

use App\Modules\Warehouse\Filament\Resources\RepairDispatches\RepairDispatchResource;
use Filament\Resources\Pages\ListRecords;

final class ListRepairDispatches extends ListRecords
{
    protected static string $resource = RepairDispatchResource::class;
}
