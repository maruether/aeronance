<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\PartPrices\Pages;

use App\Modules\Billing\Filament\Resources\PartPrices\PartPriceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditPartPrice extends EditRecord
{
    protected static string $resource = PartPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
