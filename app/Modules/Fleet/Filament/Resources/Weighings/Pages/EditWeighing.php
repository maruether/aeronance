<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings\Pages;

use App\Modules\Fleet\Filament\Resources\Weighings\WeighingResource;
use Filament\Resources\Pages\EditRecord;

final class EditWeighing extends EditRecord
{
    protected static string $resource = WeighingResource::class;

    protected function afterSave(): void
    {
        $this->record->load('entries')->recalculate();
    }
}
