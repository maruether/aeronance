<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Customers\Pages;

use App\Modules\Billing\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\EditRecord;

final class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Der Select zeigt '1'/'0'; null bleibt die leere Auswahl.
        if (array_key_exists('discount_visible', $data) && $data['discount_visible'] !== null) {
            $data['discount_visible'] = $data['discount_visible'] ? '1' : '0';
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CreateCustomer::normalise($data);
    }
}
