<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\LabourRates\Pages;

use App\Modules\Billing\Filament\Resources\LabourRates\LabourRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLabourRates extends ListRecords
{
    protected static string $resource = LabourRateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
