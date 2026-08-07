<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockLots\Pages;

use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockLots extends ListRecords
{
    protected static string $resource = StockLotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
