<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\MaintenanceManuals\Pages;

use App\Modules\Fleet\Filament\Resources\MaintenanceManuals\MaintenanceManualResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateMaintenanceManual extends CreateRecord
{
    protected static string $resource = MaintenanceManualResource::class;
}
