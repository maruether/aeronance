<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Filament\Resources\Holders\Pages;

use App\Modules\Fleet\Filament\Resources\Holders\HolderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListHolders extends ListRecords
{
    protected static string $resource = HolderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
