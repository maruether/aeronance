<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Filament\Resources\StockLots\Pages;

use App\Modules\Warehouse\Filament\Resources\StockLots\StockLotResource;
use App\Modules\Warehouse\Filament\Resources\StockLots\Tables\StockLotsTable;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * Ein Los, und was man daran tun kann.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIESE SEITE HATTE KEINE EINZIGE AKTION -- und war zugleich das Ziel jedes
 * Links aus „Was steht an". Feldtest: "die rote meldung ist da, ich kann aber
 * kein form 1 nacherfassen." Genau so: Die Meldung verwies auf eine Seite, auf
 * der sich nichts nachtragen ließ, und die Aktionen wohnten in der Liste
 * nebenan.
 *
 * Dieselben Aktionen wie in der Tabelle, nicht nachgebaute: Eine zweite
 * Fassung derselben Maske wäre nach dem ersten Feld auseinandergelaufen.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ViewStockLot extends ViewRecord
{
    protected static string $resource = StockLotResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            StockLotsTable::recordCertificateAction(),
            StockLotsTable::quarantineAction(),
            StockLotsTable::determineAction(),
        ];
    }
}
