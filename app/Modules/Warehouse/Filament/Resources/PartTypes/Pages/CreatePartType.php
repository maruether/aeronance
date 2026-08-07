<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PartTypes\Pages;

use App\Modules\Warehouse\Filament\Resources\PartTypes\PartTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePartType extends CreateRecord
{
    protected static string $resource = PartTypeResource::class;
}
