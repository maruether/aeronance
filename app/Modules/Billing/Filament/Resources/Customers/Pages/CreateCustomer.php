<?php

declare(strict_types=1);

namespace App\Modules\Billing\Filament\Resources\Customers\Pages;

use App\Modules\Billing\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::normalise($data);
    }

    /**
     * Leere Auswahl heisst null -- "Vorgabe der Organisation", nicht "nein".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        $data['country'] = strtoupper(trim((string) ($data['country'] ?? 'DE'))) ?: 'DE';
        $data['discount_visible'] = match ($data['discount_visible'] ?? null) {
            '1', 1, true => true,
            '0', 0, false => false,
            default => null,
        };

        foreach (['payment_terms_days', 'default_discount_percent', 'tax_treatment', 'customer_number'] as $field) {
            if (($data[$field] ?? '') === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
