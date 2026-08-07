<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Pages;

use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /**
     * Wer die Bestellung eintraegt, bekommt die Erinnerung.
     *
     * Nicht auswaehlbar, sondern festgehalten: Ein Auswahlfeld „an wen soll
     * erinnert werden" waere ein Feld, das jemand leer laesst -- und dann
     * erinnert die Bestellung niemanden.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_id'] ??= auth()->id();

        return $data;
    }
}
