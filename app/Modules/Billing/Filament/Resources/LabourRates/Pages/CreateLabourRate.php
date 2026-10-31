<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\LabourRates\Pages;

use App\Modules\Billing\Filament\Resources\LabourRates\LabourRateResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateLabourRate extends CreateRecord
{
    protected static string $resource = LabourRateResource::class;
}
