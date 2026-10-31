<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Customers\Pages;

use App\Modules\Billing\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
