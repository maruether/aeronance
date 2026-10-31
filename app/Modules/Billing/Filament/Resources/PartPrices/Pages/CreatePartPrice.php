<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\PartPrices\Pages;

use App\Modules\Billing\Filament\Resources\PartPrices\PartPriceResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePartPrice extends CreateRecord
{
    protected static string $resource = PartPriceResource::class;
}
