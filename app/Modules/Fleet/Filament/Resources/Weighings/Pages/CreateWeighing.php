<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Weighings\Pages;

use App\Modules\Fleet\Filament\Resources\Weighings\WeighingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateWeighing extends CreateRecord
{
    protected static string $resource = WeighingResource::class;

    /**
     * Works the sheet out and writes the answer down.
     *
     * Stored rather than left to be recomputed: a signed document keeps its
     * numbers, and recalculating a 2019 report with 2027 code would republish
     * somebody's signature over a different answer.
     */
    protected function afterCreate(): void
    {
        $this->record->load('entries')->recalculate();
    }
}
