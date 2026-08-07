<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\PurchaseOrders\Pages;

use App\Modules\Warehouse\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * „Offene Bestellungen" — der Bildschirm, auf den man nach der Lieferung geht.
 *
 * Der Filter „offen" ist ab Werk gesetzt: Wer hierher kommt, will wissen,
 * worauf noch jemand wartet, nicht was im letzten Jahr alles geliefert wurde.
 */
final class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
