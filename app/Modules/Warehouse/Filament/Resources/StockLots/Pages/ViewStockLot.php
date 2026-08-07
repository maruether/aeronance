<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockLots\Pages;

use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewStockLot extends ViewRecord
{
    protected static string $resource = StockLotResource::class;
}
