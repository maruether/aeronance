<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\Suppliers\Pages;

use App\Modules\Warehouse\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;
}
