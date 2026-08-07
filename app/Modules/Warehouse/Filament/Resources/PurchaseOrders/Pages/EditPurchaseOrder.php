<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Pages;

use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Resources\Pages\EditRecord;

final class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;
}
