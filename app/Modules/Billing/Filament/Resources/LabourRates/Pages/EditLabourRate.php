<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\LabourRates\Pages;

use App\Modules\Billing\Filament\Resources\LabourRates\LabourRateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditLabourRate extends EditRecord
{
    protected static string $resource = LabourRateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
